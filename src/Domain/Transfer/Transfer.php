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
    ) {
    }

    public static function initiate(
        TransferId $id,
        AccountId $fromAccountId,
        AccountId $toAccountId,
        Money $amount,
        string $idempotencyKey,
        UserId $initiatedByUserId,
        string $ipAddress,
        string $userAgent,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            $id,
            $fromAccountId,
            $toAccountId,
            $amount,
            $idempotencyKey,
            $initiatedByUserId,
            $ipAddress,
            $userAgent,
            TransferStatus::Pending,
            null,
            null,
            null,
            null,
            null,
            $now,
            $now
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        assert(is_string($row['id']));
        assert(is_string($row['from_account_id']));
        assert(is_string($row['to_account_id']));
        assert(is_string($row['currency']));
        assert(is_string($row['amount']) || is_numeric($row['amount']));
        assert(is_string($row['idempotency_key']));
        assert(is_string($row['initiated_by_user_id']));
        assert(is_string($row['status']));
        assert(is_string($row['created_at']));
        assert(is_string($row['updated_at']));

        $currency = new CurrencyCode($row['currency']);

        $fromAmount = null;
        if (isset($row['from_amount'], $row['from_currency'])) {
            assert(is_string($row['from_amount']) || is_numeric($row['from_amount']));
            assert(is_string($row['from_currency']));
            $fromAmount = new Money((string) $row['from_amount'], new CurrencyCode($row['from_currency']));
        }

        $toAmount = null;
        if (isset($row['to_amount'], $row['to_currency'])) {
            assert(is_string($row['to_amount']) || is_numeric($row['to_amount']));
            assert(is_string($row['to_currency']));
            $toAmount = new Money((string) $row['to_amount'], new CurrencyCode($row['to_currency']));
        }

        $fxRate = null;
        if (isset($row['fx_rate'])) {
            assert(is_string($row['fx_rate']));
            $fxRate = $row['fx_rate'];
        }
        $fxRateProvider = null;
        if (isset($row['fx_rate_provider'])) {
            assert(is_string($row['fx_rate_provider']));
            $fxRateProvider = $row['fx_rate_provider'];
        }
        $failureReason = null;
        if (isset($row['failure_reason'])) {
            assert(is_string($row['failure_reason']));
            $failureReason = $row['failure_reason'];
        }
        $ipAddress = '';
        if (isset($row['ip_address'])) {
            assert(is_string($row['ip_address']));
            $ipAddress = $row['ip_address'];
        }
        $userAgent = '';
        if (isset($row['user_agent'])) {
            assert(is_string($row['user_agent']));
            $userAgent = $row['user_agent'];
        }

        return new self(
            id: new TransferId($row['id']),
            fromAccountId: new AccountId($row['from_account_id']),
            toAccountId: new AccountId($row['to_account_id']),
            amount: new Money((string) $row['amount'], $currency),
            idempotencyKey: $row['idempotency_key'],
            initiatedByUserId: new UserId($row['initiated_by_user_id']),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            status: TransferStatus::from($row['status']),
            fromAmount: $fromAmount,
            toAmount: $toAmount,
            fxRate: $fxRate,
            fxRateProvider: $fxRateProvider,
            failureReason: $failureReason,
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
