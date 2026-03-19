<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\User\UserId;

interface AccountRepository
{
    public function findById(AccountId $id): ?Account;

    public function findByIdForUpdate(AccountId $id): ?Account;

    /** @return Account[] */
    public function findByUserId(UserId $userId): array;

    public function save(Account $account): void;

    public function nextIdentity(): AccountId;

    public function existsForUserAndCurrency(UserId $userId, string $currency): bool;
}
