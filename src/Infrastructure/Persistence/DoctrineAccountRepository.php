<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\AccountStatus;
use App\Domain\User\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

final readonly class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findById(AccountId $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM accounts WHERE id = ?',
            [(string) $id],
        );

        return $row !== false ? Account::fromRow($row) : null;
    }

    public function findByIdForUpdate(AccountId $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM accounts WHERE id = ? FOR UPDATE',
            [(string) $id],
        );

        return $row !== false ? Account::fromRow($row) : null;
    }

    /** @return Account[] */
    public function findByUserId(UserId $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM accounts WHERE user_id = ? ORDER BY created_at ASC',
            [(string) $userId],
        );

        return array_map(Account::fromRow(...), $rows);
    }

    public function save(Account $account): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO accounts (id, user_id, currency, balance, name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (id) DO UPDATE SET
                    balance = EXCLUDED.balance,
                    name = EXCLUDED.name,
                    status = EXCLUDED.status,
                    updated_at = EXCLUDED.updated_at',
                [
                    (string) $account->id,
                    (string) $account->userId,
                    (string) $account->currency,
                    (string) $account->balance->amount,
                    $account->name,
                    $account->status->value,
                    $account->createdAt->format('Y-m-d H:i:s'),
                    $account->updatedAt->format('Y-m-d H:i:s'),
                ],
            );
        } catch (UniqueConstraintViolationException $e) {
            throw new DomainException(
                "User already has a {$account->currency} account.",
                0,
                $e,
            );
        }
    }

    public function nextIdentity(): AccountId
    {
        return AccountId::generate();
    }

    public function existsForUserAndCurrency(UserId $userId, string $currency): bool
    {
        $raw = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM accounts WHERE user_id = ? AND currency = ? AND status != ?',
            [(string) $userId, $currency, AccountStatus::Closed->value],
        );

        assert(is_int($raw) || is_string($raw));

        return (int) $raw > 0;
    }
}
