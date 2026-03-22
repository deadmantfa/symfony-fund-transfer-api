<?php

declare(strict_types=1);

use App\Application\Account\CreateAccountCommand;
use App\Application\Account\CreateAccountHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\User\UserId;

test('creates account for valid user and currency', function (): void {
    $repo = $this->createMock(AccountRepository::class);
    $repo->method('existsForUserAndCurrency')->willReturn(false);
    $repo->method('nextIdentity')->willReturn(AccountId::generate());
    $repo->expects($this->once())->method('save');

    $handler = new CreateAccountHandler($repo);
    $account = $handler(new CreateAccountCommand(
        userId: (string) UserId::generate(),
        currency: 'USD',
        name: 'My Account',
    ));

    expect($account->currency->value)->toBe('USD');
});

test('throws if user already has account in that currency', function (): void {
    $repo = $this->createStub(AccountRepository::class);
    $repo->method('existsForUserAndCurrency')->willReturn(true);

    $handler = new CreateAccountHandler($repo);
    expect(fn (): Account => $handler(new CreateAccountCommand((string) UserId::generate(), 'USD', 'My Account')))
        ->toThrow(DomainException::class);
});

test('normalises lowercase currency to uppercase', function (): void {
    $repo = $this->createMock(AccountRepository::class);
    $repo->expects($this->once())
        ->method('existsForUserAndCurrency')
        ->with($this->anything(), 'EUR')  // must be uppercased
        ->willReturn(false);
    $repo->method('nextIdentity')->willReturn(AccountId::generate());
    $repo->expects($this->once())->method('save');

    $handler = new CreateAccountHandler($repo);
    $account = $handler(new CreateAccountCommand(
        userId: (string) UserId::generate(),
        currency: 'eur',  // lowercase input
        name: 'Euro Account',
    ));

    expect($account->currency->value)->toBe('EUR');
});
