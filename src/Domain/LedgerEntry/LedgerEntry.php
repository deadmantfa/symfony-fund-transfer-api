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
        public readonly LedgerEntryId $id,
        public readonly TransferId $transferId,
        public readonly AccountId $accountId,
        public readonly EntryType $entryType,
        public readonly Money $amount,
        public readonly Money $balanceBefore,
        public readonly Money $balanceAfter,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function record(
        LedgerEntryId $id, TransferId $transferId, AccountId $accountId,
        EntryType $entryType, Money $amount, Money $balanceBefore, Money $balanceAfter,
    ): self {
        return new self($id, $transferId, $accountId, $entryType, $amount, $balanceBefore, $balanceAfter, new DateTimeImmutable());
    }
}
