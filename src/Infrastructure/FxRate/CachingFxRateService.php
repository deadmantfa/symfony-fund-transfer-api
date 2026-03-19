<?php

declare(strict_types=1);

namespace App\Infrastructure\FxRate;

use App\Domain\FxRate\FxRate;
use App\Domain\FxRate\FxRateId;
use App\Domain\FxRate\FxRateProviderInterface;
use App\Domain\FxRate\FxRateRepository;
use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use Brick\Math\BigDecimal;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

final class CachingFxRateService
{
    public function __construct(
        private readonly FxRateProviderInterface $provider,
        private readonly CacheItemPoolInterface $cache,
        private readonly FxRateRepository $fxRateRepository,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtl,
    ) {}

    public function getRate(CurrencyCode $from, CurrencyCode $to): FxRate
    {
        // 1. Same currency — trivial
        if ($from->value === $to->value) {
            return new FxRate(
                id: FxRateId::generate(),
                baseCurrency: $from,
                targetCurrency: $to,
                rate: BigDecimal::of('1')->toScale(10),
                provider: 'identity',
                fetchedAt: new \DateTimeImmutable(),
            );
        }

        // 2. Redis cache
        $cacheKey = "fx_rate_{$from}_{$to}";
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            $cached = $item->get();
            assert($cached instanceof FxRate);

            return $cached;
        }

        // 3. Provider (Frankfurter)
        try {
            $rate = $this->provider->getRate($from, $to);
            $this->fxRateRepository->save($rate);

            $item->set($rate)->expiresAfter($this->cacheTtl);
            $this->cache->save($item);

            // Also cache stale version with longer TTL for fallback
            $staleItem = $this->cache->getItem("fx_rate_stale_{$from}_{$to}");
            $staleItem->set($rate)->expiresAfter($this->cacheTtl * 24);
            $this->cache->save($staleItem);

            return $rate;
        } catch (FxRateUnavailableException) {
            $this->logger->warning("FX provider unavailable for {$from}/{$to}, trying stale cache");
        }

        // 4. Stale Redis cache
        $staleItem = $this->cache->getItem("fx_rate_stale_{$from}_{$to}");
        if ($staleItem->isHit()) {
            $this->logger->warning("Using stale cached FX rate for {$from}/{$to}");
            $stale = $staleItem->get();
            assert($stale instanceof FxRate);

            return $stale;
        }

        // 5. DB fallback
        $fallback = $this->fxRateRepository->findLatest($from, $to);

        if ($fallback !== null) {
            $this->logger->warning(
                "Using DB fallback FX rate for {$from}/{$to} (fetched at {$fallback->fetchedAt->format('Y-m-d H:i:s')})",
            );

            return $fallback;
        }

        throw new FxRateUnavailableException($from, $to);
    }
}
