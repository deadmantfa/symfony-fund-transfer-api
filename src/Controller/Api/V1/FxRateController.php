<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Domain\FxRate\FxRateServiceInterface;
use App\Domain\FxRate\FxRateUnavailableException;
use App\Domain\Shared\ValueObject\CurrencyCode;
use DateTimeInterface;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'FX Rates')]
#[Route('/api/v1/fx-rates')]
final readonly class FxRateController
{
    public function __construct(
        private FxRateServiceInterface $fxRateService,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/fx-rates/{from}/{to}',
        summary: 'Get the current FX rate between two currencies',
        parameters: [
            new OA\Parameter(name: 'from', in: 'path', required: true, example: 'USD', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'to', in: 'path', required: true, example: 'EUR', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'FX rate data'),
            new OA\Response(response: 422, description: 'Invalid currency code'),
            new OA\Response(response: 503, description: 'FX rate service unavailable'),
        ],
        security: [],
    )]
    #[Route('/{from}/{to}', methods: ['GET'])]
    public function pair(string $from, string $to): JsonResponse
    {
        try {
            $fromCurrency = new CurrencyCode(strtoupper($from));
            $toCurrency   = new CurrencyCode(strtoupper($to));
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        try {
            $rate = $this->fxRateService->getRate($fromCurrency, $toCurrency);
        } catch (FxRateUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, $e->getMessage(), $e);
        }

        return new JsonResponse([
            'data' => [
                'base_currency'   => (string) $rate->baseCurrency,
                'target_currency' => (string) $rate->targetCurrency,
                'rate'            => (string) $rate->rate,
                'provider'        => $rate->provider,
                'fetched_at'      => $rate->fetchedAt->format(DateTimeInterface::ATOM),
            ],
        ]);
    }
}
