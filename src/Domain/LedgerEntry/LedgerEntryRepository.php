<?php

declare(strict_types=1);

namespace App\Domain\LedgerEntry;

use App\Domain\Account\AccountId;

interface LedgerEntryRepository
{
    public function append(LedgerEntry $entry): void;

    /** @return LedgerEntry[] */
    public function findByAccountId(AccountId $accountId, ?string $cursor, int $limit): array;

    public function nextIdentity(): LedgerEntryId;
}
