<?php

declare(strict_types=1);

namespace App\Domain\FxRate;

use App\Domain\Shared\ValueObject\CurrencyCode;
use RuntimeException;

final class FxRateUnavailableException extends RuntimeException
{
    public function __construct(CurrencyCode $from, CurrencyCode $to)
    {
        parent::__construct("FX rate unavailable for {$from}/{$to}");
    }
}
