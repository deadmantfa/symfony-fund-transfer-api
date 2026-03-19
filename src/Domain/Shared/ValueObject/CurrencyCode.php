<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

readonly final class CurrencyCode
{
    public function __construct(public readonly string $value)
    {
        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            throw new \InvalidArgumentException(
                "Invalid currency code: {$value}. Must be 3 uppercase letters (ISO 4217)."
            );
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
