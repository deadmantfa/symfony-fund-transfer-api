<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;

final class UpdateAccountHandler
{
    public function __construct(private readonly AccountRepository $accountRepository)
    {
    }

    public function __invoke(UpdateAccountCommand $command): void
    {
        $account = $this->accountRepository->findById(new AccountId($command->accountId));
        if ($account === null) {
            throw new \DomainException('Account not found.');
        }
        if ($command->name !== null) {
            $account->rename($command->name);
        }
        $this->accountRepository->save($account);
    }
}
