<?php

declare(strict_types=1);

namespace App\Tests\Integration\Concurrency;

use App\Application\Transfer\ProcessTransferCommand;
use App\Application\Transfer\ProcessTransferHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\AuditLog\AuditLogId;
use App\Domain\AuditLog\AuditLogRepository;
use App\Domain\FxRate\FxRateRepository;
use App\Domain\LedgerEntry\LedgerEntryId;
use App\Domain\LedgerEntry\LedgerEntryRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use App\Domain\User\UserId;
use App\Infrastructure\FxRate\CachingFxRateService;
use App\Infrastructure\FxRate\StubFxRateProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PessimisticLockTest extends TestCase
{
    private function makePendingTransfer(string $fromId, string $toId, string $amount): Transfer
    {
        return Transfer::initiate(
            id: TransferId::generate(),
            fromAccountId: new AccountId($fromId),
            toAccountId: new AccountId($toId),
            amount: new Money($amount, new CurrencyCode('USD')),
            idempotencyKey: 'k-' . uniqid(),
            initiatedByUserId: UserId::generate(),
            ipAddress: '127.0.0.1',
            userAgent: 'test',
        );
    }

    private function makeFxService(): CachingFxRateService
    {
        $fxRepo = new class () implements FxRateRepository {
            public function findLatest(CurrencyCode $from, CurrencyCode $to): ?\App\Domain\FxRate\FxRate
            {
                return null;
            }
            public function save(\App\Domain\FxRate\FxRate $rate): void {}
            public function nextIdentity(): \App\Domain\FxRate\FxRateId
            {
                return \App\Domain\FxRate\FxRateId::generate();
            }
        };

        return new CachingFxRateService(
            new StubFxRateProvider(),
            new ArrayAdapter(),
            $fxRepo,
            new NullLogger(),
            300,
        );
    }

    public function test_sequential_transfers_from_underfunded_account_second_fails(): void
    {
        $fromIdStr = (string) AccountId::generate();
        $toIdStr   = (string) AccountId::generate();

        // Account has $100; each transfer tries $80 — only the first should succeed
        $fromAccount = Account::open(new AccountId($fromIdStr), UserId::generate(), new CurrencyCode('USD'), 'From');
        $fromAccount->credit(new Money('100.00', new CurrencyCode('USD')));

        $toAccount = Account::open(new AccountId($toIdStr), UserId::generate(), new CurrencyCode('USD'), 'To');

        $transfer1 = $this->makePendingTransfer($fromIdStr, $toIdStr, '80.00');
        $transfer2 = $this->makePendingTransfer($fromIdStr, $toIdStr, '80.00');

        // Shared mutable account state (simulates shared DB row)
        $accountMap = [
            $fromIdStr => $fromAccount,
            $toIdStr   => $toAccount,
        ];

        $this->processTransfer($transfer1, $accountMap);
        $this->processTransfer($transfer2, $accountMap);

        $this->assertSame(TransferStatus::Completed, $transfer1->status);
        $this->assertSame(TransferStatus::Failed, $transfer2->status);
        $this->assertSame('insufficient_funds', $transfer2->failureReason);
        // Balance after first debit: 100 - 80 = 20
        $this->assertSame('20.00000000', (string) $fromAccount->balance->amount);
    }

    /** @param array<string, Account> $accountMap */
    private function processTransfer(Transfer $transfer, array &$accountMap): void
    {
        $fromIdStr = (string) $transfer->fromAccountId;
        $toIdStr   = (string) $transfer->toAccountId;

        /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $conn */
        $conn = $this->createMock(Connection::class);

        /** @var TransferRepository&\PHPUnit\Framework\MockObject\MockObject $transferRepo */
        $transferRepo = $this->createMock(TransferRepository::class);
        $transferRepo->method('findByIdForUpdate')->willReturn($transfer);
        $transferRepo->method('save');

        /** @var AccountRepository&\PHPUnit\Framework\MockObject\MockObject $accountRepo */
        $accountRepo = $this->createMock(AccountRepository::class);
        $accountRepo->method('findByIdForUpdate')->willReturnCallback(
            fn (AccountId $id) => $accountMap[(string) $id]
        );
        $accountRepo->method('save');

        /** @var LedgerEntryRepository&\PHPUnit\Framework\MockObject\MockObject $ledgerRepo */
        $ledgerRepo = $this->createMock(LedgerEntryRepository::class);
        $ledgerRepo->method('nextIdentity')->willReturn(LedgerEntryId::generate());

        /** @var AuditLogRepository&\PHPUnit\Framework\MockObject\MockObject $auditRepo */
        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->method('nextIdentity')->willReturn(AuditLogId::generate());

        $handler = new ProcessTransferHandler($conn, $transferRepo, $accountRepo, $ledgerRepo, $auditRepo, $this->makeFxService());
        $handler(new ProcessTransferCommand((string) $transfer->id));
    }
}
