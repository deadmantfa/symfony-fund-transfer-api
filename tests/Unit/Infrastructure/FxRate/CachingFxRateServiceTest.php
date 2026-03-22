<?php

declare(strict_types=1);

use App\Domain\FxRate\FxRate;
use App\Domain\FxRate\FxRateId;
use App\Domain\FxRate\FxRateRepository;
use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Infrastructure\FxRate\CachingFxRateService;
use App\Infrastructure\FxRate\StubFxRateProvider;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

function makeFallbackFxRate(): FxRate
{
    return new FxRate(
        id: FxRateId::generate(),
        baseCurrency: new CurrencyCode('USD'),
        targetCurrency: new CurrencyCode('EUR'),
        rate: BigDecimal::of('0.90'),
        provider: 'frankfurter',
        fetchedAt: new DateTimeImmutable('-2 hours'),
    );
}

test('returns rate from provider on cache miss', function (): void {
    /** @var FxRateRepository $repo */
    $repo = $this->createStub(FxRateRepository::class);
    $repo->method('findLatest')->willReturn(null);

    $service = new CachingFxRateService(
        new StubFxRateProvider(),
        new ArrayAdapter(),
        $repo,
        new NullLogger(),
        3600,
    );

    $rate = $service->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR'));
    expect($rate->provider)->toBe('stub');
});

test('same currency returns rate of 1', function (): void {
    /** @var FxRateRepository $repo */
    $repo = $this->createStub(FxRateRepository::class);

    $service = new CachingFxRateService(
        new StubFxRateProvider(),
        new ArrayAdapter(),
        $repo,
        new NullLogger(),
        3600,
    );

    $rate = $service->getRate(new CurrencyCode('USD'), new CurrencyCode('USD'));
    expect($rate->rate->isEqualTo('1'))->toBeTrue();
});

test('uses DB fallback when provider fails', function (): void {
    $failingProvider = new class () extends StubFxRateProvider {
        public function getRate(CurrencyCode $from, CurrencyCode $to): FxRate
        {
            throw new FxRateUnavailableException($from, $to);
        }
    };

    /** @var FxRateRepository $repo */
    $repo = $this->createStub(FxRateRepository::class);
    $repo->method('findLatest')->willReturn(makeFallbackFxRate());

    $service = new CachingFxRateService(
        $failingProvider,
        new ArrayAdapter(),
        $repo,
        new NullLogger(),
        3600,
    );

    $rate = $service->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR'));
    expect($rate->provider)->toBe('frankfurter');
});

test('throws when provider fails and no DB fallback', function (): void {
    $failingProvider = new class () extends StubFxRateProvider {
        public function getRate(CurrencyCode $from, CurrencyCode $to): FxRate
        {
            throw new FxRateUnavailableException($from, $to);
        }
    };

    /** @var FxRateRepository $repo */
    $repo = $this->createStub(FxRateRepository::class);
    $repo->method('findLatest')->willReturn(null);

    $service = new CachingFxRateService(
        $failingProvider,
        new ArrayAdapter(),
        $repo,
        new NullLogger(),
        3600,
    );

    expect(fn (): FxRate => $service->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR')))
        ->toThrow(FxRateUnavailableException::class);
});

test('returns cached rate on second call without hitting provider twice', function (): void {
    /** @var FxRateRepository&MockObject $repo */
    $repo = $this->createMock(FxRateRepository::class);
    $repo->expects($this->once())->method('save');

    $service = new CachingFxRateService(
        new StubFxRateProvider(),
        new ArrayAdapter(),
        $repo,
        new NullLogger(),
        3600,
    );

    $rate1 = $service->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR'));
    $rate2 = $service->getRate(new CurrencyCode('USD'), new CurrencyCode('EUR'));

    expect($rate1->id->value)->toBe($rate2->id->value);
});
