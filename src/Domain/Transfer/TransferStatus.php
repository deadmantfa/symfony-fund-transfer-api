<?php

declare(strict_types=1);

namespace App\Domain\Transfer;

enum TransferStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default                       => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending    => $next === self::Processing || $next === self::Failed,
            self::Processing => $next === self::Completed || $next === self::Failed,
            default          => false,
        };
    }
}
