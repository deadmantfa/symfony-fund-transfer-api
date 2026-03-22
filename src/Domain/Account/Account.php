<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\UserId;
use DateTimeImmutable;
use DomainException;

final class Account
{
    private function __construct(
        public private(set) readonly AccountId $id,
        public private(set) readonly UserId $userId,
        public private(set) readonly CurrencyCode $currency,
        public private(set) string $name,
        public private(set) Money $balance,
        public private(set) AccountStatus $status,
        public private(set) readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {
    }

    public static function open(AccountId $id, UserId $userId, CurrencyCode $currency, string $name): self
    {
        $now = new DateTimeImmutable();

        return new self(
            $id,
            $userId,
            $currency,
            $name,
            new Money('0.00000000', $currency),
            AccountStatus::Active,
            $now,
            $now
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        assert(is_string($row['id']));
        assert(is_string($row['user_id']));
        assert(is_string($row['currency']));
        assert(is_string($row['name']));
        assert(is_string($row['balance']) || is_numeric($row['balance']));
        assert(is_string($row['status']));
        assert(is_string($row['created_at']));
        assert(is_string($row['updated_at']));

        $currency = new CurrencyCode($row['currency']);

        return new self(
            id: new AccountId($row['id']),
            userId: new UserId($row['user_id']),
            currency: $currency,
            name: $row['name'],
            balance: new Money((string) $row['balance'], $currency),
            status: AccountStatus::from($row['status']),
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }

    public function credit(Money $amount): void
    {
        $this->assertActive();
        $this->balance  = $this->balance->add($amount);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function debit(Money $amount): void
    {
        $this->assertActive();
        if (!$this->balance->isSufficientFor($amount)) {
            throw new DomainException("Insufficient funds: balance {$this->balance} < debit {$amount}");
        }
        $this->balance  = $this->balance->subtract($amount);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function freeze(): void
    {
        $this->assertActive();
        $this->status    = AccountStatus::Frozen;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function unfreeze(): void
    {
        if ($this->status !== AccountStatus::Frozen) {
            throw new DomainException('Account is not frozen.');
        }
        $this->status    = AccountStatus::Active;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function close(): void
    {
        if (!$this->balance->isZero()) {
            throw new DomainException('Cannot close account with non-zero balance.');
        }
        $this->status    = AccountStatus::Closed;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function rename(string $name): void
    {
        $this->name      = $name;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isOwnedBy(UserId $userId): bool
    {
        return $this->userId->equals($userId);
    }

    private function assertActive(): void
    {
        if (!$this->status->allowsTransactions()) {
            throw new DomainException("Account {$this->id} is not active (status: {$this->status->value}).");
        }
    }
}
