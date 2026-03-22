<?php

declare(strict_types=1);

namespace App\Domain\LedgerEntry;

use App\Domain\Account\AccountId;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Transfer\TransferId;
use DateTimeImmutable;

/** Immutable — never update, never delete */
final readonly class LedgerEntry
{
    private function __construct(
        public LedgerEntryId $id,
        public TransferId $transferId,
        public AccountId $accountId,
        public EntryType $entryType,
        public Money $amount,
        public Money $balanceBefore,
        public Money $balanceAfter,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function record(
        LedgerEntryId $id,
        TransferId $transferId,
        AccountId $accountId,
        EntryType $entryType,
        Money $amount,
        Money $balanceBefore,
        Money $balanceAfter,
    ): self {
        return new self($id, $transferId, $accountId, $entryType, $amount, $balanceBefore, $balanceAfter, new DateTimeImmutable());
    }

    public static function fromRow(
        LedgerEntryId $id,
        TransferId $transferId,
        AccountId $accountId,
        EntryType $entryType,
        Money $amount,
        Money $balanceBefore,
        Money $balanceAfter,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $transferId, $accountId, $entryType, $amount, $balanceBefore, $balanceAfter, $createdAt);
    }
}
