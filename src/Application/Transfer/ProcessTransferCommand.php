<?php

declare(strict_types=1);

namespace App\Application\Transfer;

final readonly class ProcessTransferCommand
{
    public function __construct(public readonly string $transferId) {}
}
