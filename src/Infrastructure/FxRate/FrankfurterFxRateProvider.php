<?php

declare(strict_types=1);

namespace App\Infrastructure\FxRate;

use App\Domain\FxRate\FxRate;
use App\Domain\FxRate\FxRateId;
use App\Domain\FxRate\FxRateProviderInterface;
use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class FrankfurterFxRateProvider implements FxRateProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
    ) {
    }

    public function getRate(CurrencyCode $from, CurrencyCode $to): FxRate
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                "{$this->baseUrl}/latest",
                ['query' => ['from' => (string) $from, 'to' => (string) $to]],
            );

            $data = $response->toArray();

            if (!isset($data['rates'][(string) $to])) {
                throw new FxRateUnavailableException($from, $to);
            }

            return new FxRate(
                id: FxRateId::generate(),
                baseCurrency: $from,
                targetCurrency: $to,
                rate: BigDecimal::of((string) $data['rates'][(string) $to])->toScale(10),
                provider: 'frankfurter',
                fetchedAt: new DateTimeImmutable(),
            );
        } catch (FxRateUnavailableException $e) {
            throw $e;
        } catch (Throwable) {
            throw new FxRateUnavailableException($from, $to);
        }
    }

    public function getSupportedCurrencies(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->baseUrl}/currencies");

            return array_keys($response->toArray());
        } catch (Throwable) {
            return [];
        }
    }
}
