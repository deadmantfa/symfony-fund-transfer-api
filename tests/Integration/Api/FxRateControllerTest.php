<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FxRateControllerTest extends WebTestCase
{
    public function test_get_rate_for_pair(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/fx-rates/USD/EUR');

        $this->assertResponseIsSuccessful();
        /** @var array{data: array{base_currency: string, target_currency: string, rate: string, provider: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('rate', $body['data']);
        $this->assertArrayHasKey('provider', $body['data']);
        $this->assertSame('USD', $body['data']['base_currency']);
        $this->assertSame('EUR', $body['data']['target_currency']);
    }

    public function test_same_currency_returns_rate_of_one(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/fx-rates/USD/USD');

        $this->assertResponseIsSuccessful();
        /** @var array{data: array{rate: string, provider: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('1.0000000000', $body['data']['rate']);
        $this->assertSame('identity', $body['data']['provider']);
    }

    public function test_invalid_currency_code_returns_422(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/fx-rates/USD/TOOLONG');

        $this->assertResponseStatusCodeSame(422);
    }

    public function test_lowercase_currency_is_normalized(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/fx-rates/usd/eur');

        $this->assertResponseIsSuccessful();
        /** @var array{data: array{base_currency: string, target_currency: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('USD', $body['data']['base_currency']);
        $this->assertSame('EUR', $body['data']['target_currency']);
    }
}
