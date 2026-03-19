<?php

declare(strict_types=1);

namespace App\Domain\FxRate;

use App\Domain\Shared\ValueObject\CurrencyCode;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

final readonly class FxRate
{
    public function __construct(
        public readonly FxRateId $id,
        public readonly CurrencyCode $baseCurrency,
        public readonly CurrencyCode $targetCurrency,
        public readonly BigDecimal $rate,
        public readonly string $provider,
        public readonly DateTimeImmutable $fetchedAt,
    ) {
    }
}
