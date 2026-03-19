<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class UpdateAccountCommand
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $name,
    ) {
    }
}
