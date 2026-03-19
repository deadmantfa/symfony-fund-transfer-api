<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class CreateAccountCommand
{
    public function __construct(
        public readonly string $userId,
        public readonly string $currency,
        public readonly string $name,
    ) {
    }
}
