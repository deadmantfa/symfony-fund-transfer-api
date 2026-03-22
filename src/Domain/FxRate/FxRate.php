<?php

declare(strict_types=1);

namespace App\Domain\FxRate;

use App\Domain\Shared\ValueObject\CurrencyCode;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

final readonly class FxRate
{
    public function __construct(
        public FxRateId $id,
        public CurrencyCode $baseCurrency,
        public CurrencyCode $targetCurrency,
        public BigDecimal $rate,
        public string $provider,
        public DateTimeImmutable $fetchedAt,
    ) {
    }
}
