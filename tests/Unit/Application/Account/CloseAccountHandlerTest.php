<?php

declare(strict_types=1);

use App\Application\Account\CloseAccountCommand;
use App\Application\Account\CloseAccountHandler;
use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountRepository;
use App\Domain\Account\AccountStatus;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\User\UserId;
use PHPUnit\Framework\MockObject\MockObject;

test('closes account and saves', function (): void {
    $account = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');

    /** @var AccountRepository&MockObject $repo */
    $repo = $this->createMock(AccountRepository::class);
    $repo->method('findById')->willReturn($account);
    $repo->expects($this->once())->method('save')->with($account);

    $handler = new CloseAccountHandler($repo);
    $handler(new CloseAccountCommand((string) $account->id));

    expect($account->status)->toBe(AccountStatus::Closed);
});

test('throws when account not found', function (): void {
    /** @var AccountRepository $repo */
    $repo = $this->createStub(AccountRepository::class);
    $repo->method('findById')->willReturn(null);

    $handler = new CloseAccountHandler($repo);
    expect(fn () => $handler(new CloseAccountCommand((string) AccountId::generate())))
        ->toThrow(DomainException::class, 'Account not found');
});
