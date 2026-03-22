<?php

declare(strict_types=1);

namespace App\Application\Account;

final readonly class CloseAccountCommand
{
    public function __construct(public string $accountId)
    {
    }
}
