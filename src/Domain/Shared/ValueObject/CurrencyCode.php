<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use InvalidArgumentException;
use Stringable;

final readonly class CurrencyCode implements Stringable
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            throw new InvalidArgumentException(
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
