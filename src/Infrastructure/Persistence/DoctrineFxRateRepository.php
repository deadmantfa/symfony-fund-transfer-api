<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\FxRate\FxRate;
use App\Domain\FxRate\FxRateId;
use App\Domain\FxRate\FxRateRepository;
use App\Domain\Shared\ValueObject\CurrencyCode;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

final class DoctrineFxRateRepository implements FxRateRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function findLatest(CurrencyCode $from, CurrencyCode $to): ?FxRate
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM fx_rates WHERE base_currency = ? AND target_currency = ?
             ORDER BY fetched_at DESC LIMIT 1',
            [(string) $from, (string) $to],
        );

        if ($row === false) {
            return null;
        }

        assert(is_string($row['id']));
        assert(is_string($row['base_currency']));
        assert(is_string($row['target_currency']));
        assert(is_string($row['rate']) || is_numeric($row['rate']));
        assert(is_string($row['provider']));
        assert(is_string($row['fetched_at']));

        return new FxRate(
            id: new FxRateId($row['id']),
            baseCurrency: new CurrencyCode($row['base_currency']),
            targetCurrency: new CurrencyCode($row['target_currency']),
            rate: BigDecimal::of((string) $row['rate']),
            provider: $row['provider'],
            fetchedAt: new \DateTimeImmutable($row['fetched_at']),
        );
    }

    public function save(FxRate $rate): void
    {
        $this->connection->executeStatement(
            'INSERT INTO fx_rates (id, base_currency, target_currency, rate, provider, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (base_currency, target_currency, provider)
             DO UPDATE SET rate = EXCLUDED.rate, fetched_at = EXCLUDED.fetched_at',
            [
                (string) $rate->id,
                (string) $rate->baseCurrency,
                (string) $rate->targetCurrency,
                (string) $rate->rate,
                $rate->provider,
                $rate->fetchedAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function nextIdentity(): FxRateId
    {
        return FxRateId::generate();
    }
}
