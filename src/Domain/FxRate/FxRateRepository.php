<?php

declare(strict_types=1);

namespace App\Domain\FxRate;

use App\Domain\Shared\ValueObject\CurrencyCode;

interface FxRateRepository
{
    public function findLatest(CurrencyCode $from, CurrencyCode $to): ?FxRate;

    public function save(FxRate $rate): void;

    public function nextIdentity(): FxRateId;
}
