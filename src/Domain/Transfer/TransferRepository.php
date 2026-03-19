<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

use App\Domain\Account\AccountId;

interface TransferRepository
{
    public function findById(TransferId $id): ?Transfer;

    public function findByIdForUpdate(TransferId $id): ?Transfer;

    public function findByIdempotencyKey(string $key): ?Transfer;

    /** @return Transfer[] */
    public function findByAccountId(AccountId $accountId, ?string $cursor, int $limit): array;

    public function save(Transfer $transfer): void;

    public function nextIdentity(): TransferId;

    public function existsDuplicate(AccountId $fromAccountId, AccountId $toAccountId, string $amount, string $currency, int $withinSeconds): bool;
}
