<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account\Account;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\User\UserId;

final class CreateAccountHandler
{
    public function __construct(private readonly AccountRepository $accountRepository)
    {
    }

    public function __invoke(CreateAccountCommand $command): Account
    {
        $userId   = new UserId($command->userId);
        $currency = new CurrencyCode(strtoupper($command->currency));

        if ($this->accountRepository->existsForUserAndCurrency($userId, strtoupper($command->currency))) {
            throw new \DomainException("User already has a {$currency} account.");
        }

        $account = Account::open(
            id: $this->accountRepository->nextIdentity(),
            userId: $userId,
            currency: $currency,
            name: $command->name,
        );

        $this->accountRepository->save($account);

        return $account;
    }
}
