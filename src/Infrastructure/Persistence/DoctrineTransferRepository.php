<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Account\AccountId;
use App\Domain\Transfer\Transfer;
use App\Domain\Transfer\TransferId;
use App\Domain\Transfer\TransferRepository;
use App\Domain\Transfer\TransferStatus;
use Doctrine\DBAL\Connection;

final class DoctrineTransferRepository implements TransferRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function findById(TransferId $id): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM transfers WHERE id = ?',
            [(string) $id]
        );
        return $row !== false ? Transfer::fromRow($row) : null;
    }

    public function findByIdForUpdate(TransferId $id): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM transfers WHERE id = ? FOR UPDATE',
            [(string) $id]
        );
        return $row !== false ? Transfer::fromRow($row) : null;
    }

    public function findByIdempotencyKey(string $key): ?Transfer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM transfers WHERE idempotency_key = ?',
            [$key]
        );
        return $row !== false ? Transfer::fromRow($row) : null;
    }

    /** @return Transfer[] */
    public function findByAccountId(AccountId $accountId, ?string $cursor, int $limit): array
    {
        $sql    = 'SELECT * FROM transfers WHERE (from_account_id = ? OR to_account_id = ?)';
        $params = [(string) $accountId, (string) $accountId];

        if ($cursor !== null) {
            $sql      .= ' AND created_at < ?';
            $params[] = $cursor;
        }

        $sql      .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit + 1;

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        return array_map(Transfer::fromRow(...), $rows);
    }

    public function save(Transfer $transfer): void
    {
        $this->connection->executeStatement(
            'INSERT INTO transfers
                (id, from_account_id, to_account_id, amount, currency,
                 from_amount, from_currency, to_amount, to_currency,
                 fx_rate, fx_rate_provider, status, idempotency_key,
                 failure_reason, initiated_by_user_id, ip_address, user_agent,
                 created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (id) DO UPDATE SET
                from_amount = EXCLUDED.from_amount,
                from_currency = EXCLUDED.from_currency,
                to_amount = EXCLUDED.to_amount,
                to_currency = EXCLUDED.to_currency,
                fx_rate = EXCLUDED.fx_rate,
                fx_rate_provider = EXCLUDED.fx_rate_provider,
                status = EXCLUDED.status,
                failure_reason = EXCLUDED.failure_reason,
                updated_at = EXCLUDED.updated_at',
            [
                (string) $transfer->id,
                (string) $transfer->fromAccountId,
                (string) $transfer->toAccountId,
                (string) $transfer->amount->amount,
                (string) $transfer->amount->currency,
                $transfer->fromAmount !== null ? (string) $transfer->fromAmount->amount : null,
                $transfer->fromAmount !== null ? (string) $transfer->fromAmount->currency : null,
                $transfer->toAmount !== null ? (string) $transfer->toAmount->amount : null,
                $transfer->toAmount !== null ? (string) $transfer->toAmount->currency : null,
                $transfer->fxRate,
                $transfer->fxRateProvider,
                $transfer->status->value,
                $transfer->idempotencyKey,
                $transfer->failureReason,
                (string) $transfer->initiatedByUserId,
                $transfer->ipAddress,
                $transfer->userAgent,
                $transfer->createdAt->format('Y-m-d H:i:s'),
                $transfer->updatedAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function nextIdentity(): TransferId
    {
        return TransferId::generate();
    }

    public function existsDuplicate(
        AccountId $fromAccountId,
        AccountId $toAccountId,
        string $amount,
        string $currency,
        int $withinSeconds,
    ): bool {
        $raw = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM transfers
             WHERE from_account_id = ?
               AND to_account_id = ?
               AND amount = ?
               AND currency = ?
               AND status NOT IN (?, ?)
               AND created_at > NOW() - INTERVAL '{$withinSeconds} seconds'",
            [
                (string) $fromAccountId,
                (string) $toAccountId,
                $amount,
                $currency,
                TransferStatus::Failed->value,
                TransferStatus::Completed->value,
            ]
        );
        assert(is_int($raw) || is_string($raw));
        return (int) $raw > 0;
    }
}
