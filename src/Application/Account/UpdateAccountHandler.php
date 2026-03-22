<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use DomainException;

final readonly class UpdateAccountHandler
{
    public function __construct(private AccountRepository $accountRepository)
    {
    }

    public function __invoke(UpdateAccountCommand $command): void
    {
        $account = $this->accountRepository->findById(new AccountId($command->accountId));
        if (!$account instanceof Account) {
            throw new DomainException('Account not found.');
        }
        if ($command->name !== null) {
            $account->rename($command->name);
        }
        $this->accountRepository->save($account);
    }
}
