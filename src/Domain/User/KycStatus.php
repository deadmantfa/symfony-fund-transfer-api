<?php

declare(strict_types=1);

namespace App\Domain\User;

enum KycStatus: string
{
    case Unverified = 'unverified';
    case Pending    = 'pending';
    case Verified   = 'verified';

    public function allowsTransfers(): bool
    {
        return $this === self::Verified;
    }
}
