<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Account\AccountId;
use App\Domain\LedgerEntry\EntryType;
use App\Domain\LedgerEntry\LedgerEntry;
use App\Domain\LedgerEntry\LedgerEntryId;
use App\Domain\LedgerEntry\LedgerEntryRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\TransferId;
use Doctrine\DBAL\Connection;

final class DoctrineLedgerEntryRepository implements LedgerEntryRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function append(LedgerEntry $entry): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ledger_entries
                (id, transfer_id, account_id, entry_type, amount, currency,
                 balance_before, balance_after, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $entry->id,
                (string) $entry->transferId,
                (string) $entry->accountId,
                $entry->entryType->value,
                (string) $entry->amount->amount,
                (string) $entry->amount->currency,
                (string) $entry->balanceBefore->amount,
                (string) $entry->balanceAfter->amount,
                $entry->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    /** @return LedgerEntry[] */
    public function findByAccountId(AccountId $accountId, ?string $cursor, int $limit): array
    {
        $sql    = 'SELECT * FROM ledger_entries WHERE account_id = ?';
        $params = [(string) $accountId];

        if ($cursor !== null) {
            $sql      .= ' AND created_at < ?';
            $params[] = $cursor;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit + 1;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(fn (array $row): LedgerEntry => $this->hydrateEntry($row), $rows);
    }

    public function nextIdentity(): LedgerEntryId
    {
        return LedgerEntryId::generate();
    }

    /** @param array<string, mixed> $row */
    private function hydrateEntry(array $row): LedgerEntry
    {
        assert(is_string($row['id']));
        assert(is_string($row['transfer_id']));
        assert(is_string($row['account_id']));
        assert(is_string($row['entry_type']));
        assert(is_string($row['amount']) || is_numeric($row['amount']));
        assert(is_string($row['currency']));
        assert(is_string($row['balance_before']) || is_numeric($row['balance_before']));
        assert(is_string($row['balance_after']) || is_numeric($row['balance_after']));
        assert(is_string($row['created_at']));

        $currency = new CurrencyCode($row['currency']);

        return LedgerEntry::fromRow(
            id: new LedgerEntryId($row['id']),
            transferId: new TransferId($row['transfer_id']),
            accountId: new AccountId($row['account_id']),
            entryType: EntryType::from($row['entry_type']),
            amount: new Money((string) $row['amount'], $currency),
            balanceBefore: new Money((string) $row['balance_before'], $currency),
            balanceAfter: new Money((string) $row['balance_after'], $currency),
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
