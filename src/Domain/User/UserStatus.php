<?php

declare(strict_types=1);

namespace App\Domain\User;

enum UserStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';
    case Closed    = 'closed';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
