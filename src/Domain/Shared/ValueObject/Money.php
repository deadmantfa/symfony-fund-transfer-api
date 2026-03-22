<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Stringable;

final readonly class Money implements Stringable
{
    public BigDecimal $amount;

    public function __construct(
        string|BigDecimal $amount,
        public CurrencyCode $currency,
    ) {
        $this->amount = $amount instanceof BigDecimal
            ? $amount->toScale(8, RoundingMode::HalfUp)
            : BigDecimal::of($amount)->toScale(8, RoundingMode::HalfUp);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function multiply(string|BigDecimal $rate): self
    {
        $result = $this->amount->multipliedBy($rate)->toScale(8, RoundingMode::HalfUp);

        return new self($result, $this->currency);
    }

    public function convertTo(CurrencyCode $targetCurrency, string|BigDecimal $rate): self
    {
        $result = $this->amount->multipliedBy($rate)->toScale(8, RoundingMode::HalfUp);
        return new self($result, $targetCurrency);
    }

    public function isPositive(): bool
    {
        return $this->amount->isGreaterThan(BigDecimal::zero());
    }

    public function isZero(): bool
    {
        return $this->amount->isEqualTo(BigDecimal::zero());
    }

    public function isSufficientFor(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThanOrEqualTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->amount->isEqualTo($other->amount);
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }

    public function __toString(): string
    {
        return $this->amount->__toString();
    }
}
