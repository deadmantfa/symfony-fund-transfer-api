<?php

declare(strict_types=1);

use App\Application\Account\UpdateAccountCommand;
use App\Application\Account\UpdateAccountHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\User\UserId;
use PHPUnit\Framework\MockObject\MockObject;

test('renames account when name is provided', function (): void {
    $account = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Old Name');

    /** @var AccountRepository&MockObject $repo */
    $repo = $this->createMock(AccountRepository::class);
    $repo->method('findById')->willReturn($account);
    $repo->expects($this->once())->method('save')->with($account);

    $handler = new UpdateAccountHandler($repo);
    $handler(new UpdateAccountCommand((string) $account->id, 'New Name'));

    expect($account->name)->toBe('New Name');
});

test('saves without rename when name is null', function (): void {
    $account = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Unchanged');

    /** @var AccountRepository&MockObject $repo */
    $repo = $this->createMock(AccountRepository::class);
    $repo->method('findById')->willReturn($account);
    $repo->expects($this->once())->method('save');

    $handler = new UpdateAccountHandler($repo);
    $handler(new UpdateAccountCommand((string) $account->id, null));

    expect($account->name)->toBe('Unchanged');
});

test('throws when account not found', function (): void {
    /** @var AccountRepository $repo */
    $repo = $this->createStub(AccountRepository::class);
    $repo->method('findById')->willReturn(null);

    $handler = new UpdateAccountHandler($repo);
    expect(fn () => $handler(new UpdateAccountCommand((string) AccountId::generate(), 'Name')))
        ->toThrow(DomainException::class, 'Account not found');
});
