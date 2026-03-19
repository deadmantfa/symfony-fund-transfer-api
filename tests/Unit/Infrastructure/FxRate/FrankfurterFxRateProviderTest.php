<?php

declare(strict_types=1);

use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Infrastructure\FxRate\FrankfurterFxRateProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

test('returns FxRate on successful response', function (): void {
    /** @var ResponseInterface&MockObject $response */
    $response = $this->createMock(ResponseInterface::class);
    $response->method('toArray')->willReturn([
        'base'  => 'USD',
        'rates' => ['EUR' => 0.915],
    ]);

    /** @var HttpClientInterface&MockObject $client */
    $client = $this->createMock(HttpClientInterface::class);
    $client->method('request')->willReturn($response);

    $provider = new FrankfurterFxRateProvider($client, 'https://api.frankfurter.app');
    $rate = $provider->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR'));

    expect((string) $rate->rate)->toBe('0.9150000000')
        ->and($rate->provider)->toBe('frankfurter');
});

test('throws FxRateUnavailableException on HTTP failure', function (): void {
    /** @var HttpClientInterface&MockObject $client */
    $client = $this->createMock(HttpClientInterface::class);
    $client->method('request')->willThrowException(new \RuntimeException('network error'));

    $provider = new FrankfurterFxRateProvider($client, 'https://api.frankfurter.app');

    expect(fn () => $provider->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR')))
        ->toThrow(FxRateUnavailableException::class);
});

test('throws FxRateUnavailableException when currency not in response', function (): void {
    /** @var ResponseInterface&MockObject $response */
    $response = $this->createMock(ResponseInterface::class);
    $response->method('toArray')->willReturn([
        'base'  => 'USD',
        'rates' => [],
    ]);

    /** @var HttpClientInterface&MockObject $client */
    $client = $this->createMock(HttpClientInterface::class);
    $client->method('request')->willReturn($response);

    $provider = new FrankfurterFxRateProvider($client, 'https://api.frankfurter.app');

    expect(fn () => $provider->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR')))
        ->toThrow(FxRateUnavailableException::class);
});
