<?php

declare(strict_types=1);

use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Domain\Shared\ValueObject\Money;
use App\Domain\Shared\ValueObject\Uuid;

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
    expect(fn (): Money => (new Money('100.00', new CurrencyCode('USD')))->subtract(new Money('50.00', new CurrencyCode('EUR'))))
        ->toThrow(InvalidArgumentException::class);
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
    expect(fn (): CurrencyCode => new CurrencyCode('XX'))->toThrow(InvalidArgumentException::class);
    expect(fn (): CurrencyCode => new CurrencyCode('usd'))->toThrow(InvalidArgumentException::class);
});

test('money convertTo changes currency and applies rate', function (): void {
    $usd    = new Money('100.00', new CurrencyCode('USD'));
    $eur    = $usd->convertTo(new CurrencyCode('EUR'), '0.92');
    expect($eur->currency->value)->toBe('EUR')
        ->and($eur->amount->toFloat())->toBe(92.0);
});

test('money convertTo same currency equals multiply', function (): void {
    $usd    = new Money('100.00', new CurrencyCode('USD'));
    $result = $usd->convertTo(new CurrencyCode('USD'), '1.5');
    expect($result->currency->value)->toBe('USD')
        ->and($result->amount->toFloat())->toBe(150.0);
});

test('money multiply preserves source currency', function (): void {
    $usd    = new Money('50.00', new CurrencyCode('USD'));
    $result = $usd->multiply('2.0');
    expect($result->currency->value)->toBe('USD')
        ->and($result->amount->toFloat())->toBe(100.0);
});

test('money equals returns true for identical amount and currency', function (): void {
    $a = new Money('100.00', new CurrencyCode('USD'));
    $b = new Money('100.00', new CurrencyCode('USD'));
    expect($a->equals($b))->toBeTrue();
});

test('money equals returns false for different currency', function (): void {
    $a = new Money('100.00', new CurrencyCode('USD'));
    $b = new Money('100.00', new CurrencyCode('EUR'));
    expect($a->equals($b))->toBeFalse();
});

test('money equals returns false for different amount', function (): void {
    $a = new Money('100.00', new CurrencyCode('USD'));
    $b = new Money('99.00', new CurrencyCode('USD'));
    expect($a->equals($b))->toBeFalse();
});

test('money isZero returns true for zero amount', function (): void {
    expect((new Money('0.00', new CurrencyCode('USD')))->isZero())->toBeTrue();
    expect((new Money('0.01', new CurrencyCode('USD')))->isZero())->toBeFalse();
});

test('money toString returns numeric string', function (): void {
    $m = new Money('42.50', new CurrencyCode('USD'));
    expect((string) $m)->toBe('42.50000000');
});

test('money addition throws on currency mismatch', function (): void {
    expect(fn () => (new Money('100.00', new CurrencyCode('USD')))->add(new Money('50.00', new CurrencyCode('EUR'))))
        ->toThrow(InvalidArgumentException::class);
});

test('uuid generate produces valid v4 format', function (): void {
    $uuid = Uuid::generate();
    // UUID v4: 4th group starts with '4', 5th group starts with 8, 9, a, or b
    $str = (string) $uuid;
    expect($str)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

test('uuid equals is case insensitive (lowercase left)', function (): void {
    $lower = new Uuid('550e8400-e29b-41d4-a716-446655440000');
    $upper = new Uuid('550E8400-E29B-41D4-A716-446655440000');
    expect($lower->equals($upper))->toBeTrue();
});

test('uuid equals is case insensitive (uppercase left)', function (): void {
    $upper = new Uuid('550E8400-E29B-41D4-A716-446655440000');
    $lower = new Uuid('550e8400-e29b-41d4-a716-446655440000');
    expect($upper->equals($lower))->toBeTrue();
});

test('uuid equals returns false for different values', function (): void {
    $a = Uuid::generate();
    $b = Uuid::generate();
    expect($a->equals($b))->toBeFalse();
});

test('uuid rejects invalid format', function (): void {
    expect(fn () => new Uuid('not-a-uuid'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new Uuid('550e8400-e29b-41d4-a716'))->toThrow(InvalidArgumentException::class);
});
