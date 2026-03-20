<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final readonly class InitiateTransferCommand
{
    public function __construct(
        public readonly string $userId,
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $idempotencyKey,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly ?string $note = null,
    ) {}
}
