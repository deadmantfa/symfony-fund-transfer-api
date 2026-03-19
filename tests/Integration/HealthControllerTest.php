<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');
        $this->assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('ok', $body['status']);
    }
}
