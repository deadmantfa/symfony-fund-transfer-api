<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use App\Domain\Account\AccountRepository;
use App\Domain\AuditLog\AuditLog;
use App\Domain\AuditLog\AuditLogRepository;
use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\LedgerEntry\EntryType;
use App\Domain\LedgerEntry\LedgerEntry;
use App\Domain\LedgerEntry\LedgerEntryRepository;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Infrastructure\FxRate\CachingFxRateService;
use Doctrine\DBAL\Connection;

final class ProcessTransferHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TransferRepository $transferRepository,
        private readonly AccountRepository $accountRepository,
        private readonly LedgerEntryRepository $ledgerEntryRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly CachingFxRateService $fxRateService,
    ) {
    }

    public function __invoke(ProcessTransferCommand $command): void
    {
        $this->connection->beginTransaction();
        $transfer = null;

        try {
            // 1. Lock transfer row
            $transfer = $this->transferRepository->findByIdForUpdate(
                new TransferId($command->transferId)
            );

            if ($transfer === null) {
                throw new \RuntimeException("Transfer {$command->transferId} not found.");
            }

            $transfer->markProcessing();
            $this->transferRepository->save($transfer);

            // 2. Lock accounts in ascending UUID order (deadlock prevention)
            $fromIdStr = (string) $transfer->fromAccountId;
            $toIdStr   = (string) $transfer->toAccountId;
            $firstId   = strcmp($fromIdStr, $toIdStr) < 0 ? $transfer->fromAccountId : $transfer->toAccountId;
            $secondId  = strcmp($fromIdStr, $toIdStr) < 0 ? $transfer->toAccountId : $transfer->fromAccountId;

            $first  = $this->accountRepository->findByIdForUpdate($firstId);
            $second = $this->accountRepository->findByIdForUpdate($secondId);

            if ($first === null || $second === null) {
                throw new \DomainException('Account not found during processing.');
            }

            $fromAccount = (string) $first->id === $fromIdStr ? $first : $second;
            $toAccount   = (string) $first->id === $toIdStr ? $first : $second;

            // 3. Get FX rate
            $fxRate = $this->fxRateService->getRate($fromAccount->currency, $toAccount->currency);

            // 4. Calculate amounts
            // transfer->amount is in fromAccount currency
            $debitAmount  = $transfer->amount;
            $creditAmount = $debitAmount->convertTo($toAccount->currency, (string) $fxRate->rate);

            // 5. Debit source
            $balanceBefore = $fromAccount->balance;
            $fromAccount->debit($debitAmount);
            $this->accountRepository->save($fromAccount);

            // 6. Credit destination
            $creditBefore = $toAccount->balance;
            $toAccount->credit($creditAmount);
            $this->accountRepository->save($toAccount);

            // 7. Double-entry ledger
            $this->ledgerEntryRepository->append(LedgerEntry::record(
                id: $this->ledgerEntryRepository->nextIdentity(),
                transferId: $transfer->id,
                accountId: $fromAccount->id,
                entryType: EntryType::Debit,
                amount: $debitAmount,
                balanceBefore: $balanceBefore,
                balanceAfter: $fromAccount->balance,
            ));

            $this->ledgerEntryRepository->append(LedgerEntry::record(
                id: $this->ledgerEntryRepository->nextIdentity(),
                transferId: $transfer->id,
                accountId: $toAccount->id,
                entryType: EntryType::Credit,
                amount: $creditAmount,
                balanceBefore: $creditBefore,
                balanceAfter: $toAccount->balance,
            ));

            // 8. Complete transfer
            $transfer->complete(
                fromAmount: $debitAmount,
                toAmount: $creditAmount,
                fxRate: (string) $fxRate->rate,
                fxRateProvider: $fxRate->provider,
            );
            $this->transferRepository->save($transfer);

            // 9. Audit log
            $this->auditLogRepository->append(AuditLog::record(
                id: $this->auditLogRepository->nextIdentity(),
                entityType: 'Transfer',
                entityId: (string) $transfer->id,
                action: 'completed',
                actorId: $transfer->initiatedByUserId,
                newValue: ['status' => 'completed', 'fx_rate' => (string) $fxRate->rate],
                ipAddress: $transfer->ipAddress,
                userAgent: $transfer->userAgent,
            ));

            $this->connection->commit();
        } catch (\DomainException $e) {
            $this->connection->rollBack();

            $reason = match (true) {
                str_contains($e->getMessage(), 'Insufficient') => 'insufficient_funds',
                str_contains($e->getMessage(), 'frozen')       => 'account_frozen',
                str_contains($e->getMessage(), 'closed')       => 'account_closed',
                default                                         => 'processing_error',
            };

            $transfer->fail($reason);
            $this->transferRepository->save($transfer);
            $this->auditLogRepository->append(AuditLog::record(
                id: $this->auditLogRepository->nextIdentity(),
                entityType: 'Transfer',
                entityId: (string) $transfer->id,
                action: 'failed',
                actorId: $transfer->initiatedByUserId,
                newValue: ['reason' => $reason, 'error' => $e->getMessage()],
            ));
        } catch (FxRateUnavailableException $e) {
            $this->connection->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
