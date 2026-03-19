<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use App\Infrastructure\FxRate\CachingFxRateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/fx-rates')]
final class FxRateController
{
    public function __construct(
        private readonly CachingFxRateService $fxRateService,
    ) {
    }

    #[Route('/{from}/{to}', methods: ['GET'])]
    public function pair(string $from, string $to): JsonResponse
    {
        try {
            $fromCurrency = new CurrencyCode(strtoupper($from));
            $toCurrency   = new CurrencyCode(strtoupper($to));
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        try {
            $rate = $this->fxRateService->getRate($fromCurrency, $toCurrency);
        } catch (FxRateUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'base_currency'   => (string) $rate->baseCurrency,
                'target_currency' => (string) $rate->targetCurrency,
                'rate'            => (string) $rate->rate,
                'provider'        => $rate->provider,
                'fetched_at'      => $rate->fetchedAt->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
