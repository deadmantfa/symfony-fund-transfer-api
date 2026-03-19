<?php

declare(strict_types=1);

namespace App\Domain\FxRate;

use App\Domain\Shared\ValueObject\CurrencyCode;

interface FxRateProviderInterface
{
    public function getRate(CurrencyCode $from, CurrencyCode $to): FxRate;

    /** @return string[] */
    public function getSupportedCurrencies(): array;
}
