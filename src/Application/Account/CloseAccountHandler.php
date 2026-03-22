<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use DomainException;

final readonly class CloseAccountHandler
{
    public function __construct(private AccountRepository $accountRepository)
    {
    }

    public function __invoke(CloseAccountCommand $command): void
    {
        $account = $this->accountRepository->findById(new AccountId($command->accountId));
        if (!$account instanceof Account) {
            throw new DomainException('Account not found.');
        }
        $account->close();
        $this->accountRepository->save($account);
    }
}
