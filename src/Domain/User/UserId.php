<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\ValueObject\Uuid;

readonly final class UserId
{
    public function __construct(public readonly string $value)
    {
        new Uuid($value); // validates format
    }

    public static function generate(): self
    {
        return new self((string) Uuid::generate());
    }

    public function equals(self $other): bool
    {
        return strtolower($this->value) === strtolower($other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
