<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';

    public function allowsTransactions(): bool
    {
        return $this === self::Active;
    }
}
