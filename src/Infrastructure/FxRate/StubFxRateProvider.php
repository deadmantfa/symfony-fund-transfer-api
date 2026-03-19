<?php

declare(strict_types=1);

namespace App\Infrastructure\FxRate;

use App\Domain\FxRate\FxRate;
use App\Domain\FxRate\FxRateId;
use App\Domain\FxRate\FxRateProviderInterface;
use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use Brick\Math\BigDecimal;

/**
 * Deterministic FX rates for tests — no HTTP calls.
 * All rates relative to USD.
 */
class StubFxRateProvider implements FxRateProviderInterface
{
    /** @var array<string, float> Rates relative to USD (or explicit pair key "FROM/TO") */
    private array $rates = [
        'USD' => 1.0,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'JPY' => 149.50,
        'CAD' => 1.36,
        'AUD' => 1.53,
        'CHF' => 0.88,
    ];

    public function withRate(string $from, string $to, float $rate): self
    {
        $clone = clone $this;
        $clone->rates["{$from}/{$to}"] = $rate;

        return $clone;
    }

    public function getRate(CurrencyCode $from, CurrencyCode $to): FxRate
    {
        $key = "{$from}/{$to}";

        if (isset($this->rates[$key])) {
            $rate = $this->rates[$key];
        } elseif (isset($this->rates[(string) $from], $this->rates[(string) $to])) {
            // Cross-rate via USD
            $rate = $this->rates[(string) $to] / $this->rates[(string) $from];
        } else {
            throw new FxRateUnavailableException($from, $to);
        }

        return new FxRate(
            id: FxRateId::generate(),
            baseCurrency: $from,
            targetCurrency: $to,
            rate: BigDecimal::of((string) round($rate, 10))->toScale(10),
            provider: 'stub',
            fetchedAt: new \DateTimeImmutable(),
        );
    }

    public function getSupportedCurrencies(): array
    {
        return array_values(array_filter(array_keys($this->rates), static fn (string $k) => !str_contains($k, '/')));
    }
}
