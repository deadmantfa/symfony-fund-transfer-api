<?php

declare(strict_types=1);

use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;

test('money stores amount and currency', function (): void {
    $money = new Money('100.50', new CurrencyCode('USD'));
    expect($money->amount->toFloat())->toBe(100.5)
        ->and($money->currency->value)->toBe('USD');
});

test('money addition produces correct result', function (): void {
    $a = new Money('100.00', new CurrencyCode('USD'));
    $b = new Money('50.25', new CurrencyCode('USD'));
    expect($a->add($b)->amount->toFloat())->toBe(150.25);
});

test('money subtraction produces correct result', function (): void {
    $a = new Money('100.00', new CurrencyCode('USD'));
    $b = new Money('30.00', new CurrencyCode('USD'));
    expect($a->subtract($b)->amount->toFloat())->toBe(70.0);
});

test('money cannot subtract different currencies', function (): void {
    expect(fn () => (new Money('100.00', new CurrencyCode('USD')))->subtract(new Money('50.00', new CurrencyCode('EUR'))))
        ->toThrow(\InvalidArgumentException::class);
});

test('money is positive', function (): void {
    expect((new Money('10.00', new CurrencyCode('USD')))->isPositive())->toBeTrue();
    expect((new Money('0.00', new CurrencyCode('USD')))->isPositive())->toBeFalse();
});

test('money isSufficientFor', function (): void {
    $balance = new Money('100.00', new CurrencyCode('USD'));
    $debit   = new Money('80.00', new CurrencyCode('USD'));
    expect($balance->isSufficientFor($debit))->toBeTrue();
    expect((new Money('50.00', new CurrencyCode('USD')))->isSufficientFor($debit))->toBeFalse();
});

test('invalid currency code throws', function (): void {
    expect(fn () => new CurrencyCode('XX'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => new CurrencyCode('usd'))->toThrow(\InvalidArgumentException::class);
});
