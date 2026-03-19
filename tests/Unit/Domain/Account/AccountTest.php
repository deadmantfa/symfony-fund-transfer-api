<?php

declare(strict_types=1);

use App\Domain\Account\Account;
use App\Domain\Account\AccountId;
use App\Domain\Account\AccountStatus;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\User\UserId;

test('account opens with zero balance', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    expect($a->balance->isZero())->toBeTrue()
        ->and($a->status)->toBe(AccountStatus::Active);
});

test('account can be credited', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('100.00', new CurrencyCode('USD')));
    expect($a->balance->amount->toFloat())->toBe(100.0);
});

test('account can be debited when sufficient funds', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('100.00', new CurrencyCode('USD')));
    $a->debit(new Money('40.00', new CurrencyCode('USD')));
    expect($a->balance->amount->toFloat())->toBe(60.0);
});

test('debit throws on insufficient funds', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('50.00', new CurrencyCode('USD')));
    expect(fn () => $a->debit(new Money('100.00', new CurrencyCode('USD'))))
        ->toThrow(\DomainException::class, 'Insufficient funds');
});

test('frozen account cannot be debited', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('100.00', new CurrencyCode('USD')));
    $a->freeze();
    expect(fn () => $a->debit(new Money('10.00', new CurrencyCode('USD'))))
        ->toThrow(\DomainException::class);
});

test('account cannot close with non-zero balance', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('10.00', new CurrencyCode('USD')));
    expect(fn () => $a->close())->toThrow(\DomainException::class);
});

test('account can close with zero balance', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->close();
    expect($a->status)->toBe(AccountStatus::Closed);
});
