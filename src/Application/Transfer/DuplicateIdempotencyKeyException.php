<?php

declare(strict_types=1);

namespace App\Application\Transfer;

use RuntimeException;

final class DuplicateIdempotencyKeyException extends RuntimeException
{
    /** @param array<string, mixed> $cachedResponse */
    public function __construct(public readonly array $cachedResponse)
    {
        parent::__construct('Duplicate idempotency key.');
    }
}
