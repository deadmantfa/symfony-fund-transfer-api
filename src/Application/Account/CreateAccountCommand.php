<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class CreateAccountCommand
{
    public function __construct(
        public string $userId,
        public string $currency,
        public string $name,
    ) {
    }
}
