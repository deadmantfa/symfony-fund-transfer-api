<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Account\AccountId;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\UserId;
use DateTimeImmutable;

final class Transfer
{
    private function __construct(
        public private(set) readonly TransferId $id,
        public private(set) readonly AccountId $fromAccountId,
        public private(set) readonly AccountId $toAccountId,
        public private(set) readonly Money $amount,
        public private(set) readonly string $idempotencyKey,
        public private(set) readonly UserId $initiatedByUserId,
        public private(set) readonly string $ipAddress,
        public private(set) readonly string $userAgent,
        public private(set) TransferStatus $status,
        public private(set) ?Money $fromAmount,
        public private(set) ?Money $toAmount,
        public private(set) ?string $fxRate,
        public private(set) ?string $fxRateProvider,
        public private(set) ?string $failureReason,
        public private(set) readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    public static function initiate(
        TransferId $id, AccountId $fromAccountId, AccountId $toAccountId,
        Money $amount, string $idempotencyKey, UserId $initiatedByUserId,
        string $ipAddress, string $userAgent,
    ): self {
        $now = new DateTimeImmutable();

        return new self($id, $fromAccountId, $toAccountId, $amount, $idempotencyKey,
            $initiatedByUserId, $ipAddress, $userAgent,
            TransferStatus::Pending, null, null, null, null, null, $now, $now);
    }

    public static function fromRow(array $row): self
    {
        $currency = new CurrencyCode($row['currency']);

        return new self(
            id: new TransferId($row['id']),
            fromAccountId: new AccountId($row['from_account_id']),
            toAccountId: new AccountId($row['to_account_id']),
            amount: new Money($row['amount'], $currency),
            idempotencyKey: $row['idempotency_key'],
            initiatedByUserId: new UserId($row['initiated_by_user_id']),
            ipAddress: $row['ip_address'] ?? '',
            userAgent: $row['user_agent'] ?? '',
            status: TransferStatus::from($row['status']),
            fromAmount: isset($row['from_amount'], $row['from_currency'])
                ? new Money($row['from_amount'], new CurrencyCode($row['from_currency'])) : null,
            toAmount: isset($row['to_amount'], $row['to_currency'])
                ? new Money($row['to_amount'], new CurrencyCode($row['to_currency'])) : null,
            fxRate: $row['fx_rate'] ?? null,
            fxRateProvider: $row['fx_rate_provider'] ?? null,
            failureReason: $row['failure_reason'] ?? null,
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }

    public function markProcessing(): void
    {
        $this->assertCanTransitionTo(TransferStatus::Processing);
        $this->status    = TransferStatus::Processing;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function complete(Money $fromAmount, Money $toAmount, string $fxRate, string $fxRateProvider): void
    {
        $this->assertCanTransitionTo(TransferStatus::Completed);
        $this->status          = TransferStatus::Completed;
        $this->fromAmount      = $fromAmount;
        $this->toAmount        = $toAmount;
        $this->fxRate          = $fxRate;
        $this->fxRateProvider  = $fxRateProvider;
        $this->updatedAt       = new DateTimeImmutable();
    }

    public function fail(string $reason): void
    {
        $this->assertCanTransitionTo(TransferStatus::Failed);
        $this->status         = TransferStatus::Failed;
        $this->failureReason  = $reason;
        $this->updatedAt      = new DateTimeImmutable();
    }

    public function cancel(): void
    {
        if ($this->status !== TransferStatus::Pending) {
            throw new \DomainException('Only pending transfers can be cancelled.');
        }
        $this->fail('cancelled_by_user');
    }

    private function assertCanTransitionTo(TransferStatus $next): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new \DomainException(
                "Cannot transition transfer from {$this->status->value} to {$next->value}."
            );
        }
    }
}
