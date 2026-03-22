<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final readonly class InitiateTransferCommand
{
    public function __construct(
        public string $userId,
        public string $fromAccountId,
        public string $toAccountId,
        public string $amount,
        public string $currency,
        public string $idempotencyKey,
        public string $ipAddress,
        public string $userAgent,
        public ?string $note = null,
    ) {
    }
}
