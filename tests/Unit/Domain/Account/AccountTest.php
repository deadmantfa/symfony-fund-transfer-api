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
        ->toThrow(DomainException::class, 'Insufficient funds');
});

test('frozen account cannot be debited', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('100.00', new CurrencyCode('USD')));
    $a->freeze();
    expect(fn () => $a->debit(new Money('10.00', new CurrencyCode('USD'))))
        ->toThrow(DomainException::class);
});

test('account cannot close with non-zero balance', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->credit(new Money('10.00', new CurrencyCode('USD')));
    expect(fn () => $a->close())->toThrow(DomainException::class);
});

test('account can close with zero balance', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->close();
    expect($a->status)->toBe(AccountStatus::Closed);
});

test('frozen account can be unfrozen', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->freeze();
    $a->unfreeze();
    expect($a->status)->toBe(AccountStatus::Active);
});

test('unfreeze throws when account is not frozen', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    expect(fn () => $a->unfreeze())->toThrow(DomainException::class, 'not frozen');
});

test('account can be renamed', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Old');
    $a->rename('New Name');
    expect($a->name)->toBe('New Name');
});

test('isOwnedBy returns true for owner and false for others', function (): void {
    $owner = UserId::generate();
    $other = UserId::generate();
    $a = Account::open(AccountId::generate(), $owner, new CurrencyCode('USD'), 'Test');
    expect($a->isOwnedBy($owner))->toBeTrue()
        ->and($a->isOwnedBy($other))->toBeFalse();
});

test('frozen account cannot be credited', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->freeze();
    expect(fn () => $a->credit(new Money('10.00', new CurrencyCode('USD'))))->toThrow(DomainException::class);
});

test('closed account cannot be debited', function (): void {
    $a = Account::open(AccountId::generate(), UserId::generate(), new CurrencyCode('USD'), 'Test');
    $a->close();
    expect(fn () => $a->debit(new Money('10.00', new CurrencyCode('USD'))))->toThrow(DomainException::class);
});

test('fromRow reconstructs account from database row', function (): void {
    $id     = (string) AccountId::generate();
    $userId = (string) UserId::generate();
    $now    = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $row = [
        'id'         => $id,
        'user_id'    => $userId,
        'currency'   => 'EUR',
        'name'       => 'My Account',
        'balance'    => '250.00',
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $account = Account::fromRow($row);
    expect((string) $account->id)->toBe($id)
        ->and($account->currency->value)->toBe('EUR')
        ->and($account->name)->toBe('My Account')
        ->and($account->balance->amount->toFloat())->toBe(250.0)
        ->and($account->status)->toBe(AccountStatus::Active);
});

test('fromRow accepts numeric balance from database', function (): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $row = [
        'id'         => (string) AccountId::generate(),
        'user_id'    => (string) UserId::generate(),
        'currency'   => 'USD',
        'name'       => 'Numeric',
        'balance'    => 100.50, // float from DB
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $account = Account::fromRow($row);
    expect($account->balance->amount->toFloat())->toBe(100.5);
});

test('fromRow rejects non-string required fields', function (string $field, mixed $badValue): void {
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $row = [
        'id'         => (string) AccountId::generate(),
        'user_id'    => (string) UserId::generate(),
        'currency'   => 'USD',
        'name'       => 'Test',
        'balance'    => '0.00',
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $row[$field] = $badValue;

    expect(fn () => Account::fromRow($row))->toThrow(AssertionError::class);
})->with([
    'non-string id'         => ['id', []],
    'non-string user_id'    => ['user_id', []],
    'non-string currency'   => ['currency', []],
    'non-string name'       => ['name', []],
    'non-string status'     => ['status', []],
    'non-string created_at' => ['created_at', []],
    'non-string updated_at' => ['updated_at', []],
    // balance must be string or numeric; passing array fails both checks
    'non-numeric balance'   => ['balance', []],
]);
