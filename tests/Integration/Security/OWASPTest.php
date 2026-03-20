<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OWASPTest extends WebTestCase
{
    public function test_404_returns_problem_details(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/nonexistent');

        $this->assertResponseStatusCodeSame(404);
        $this->assertResponseHeaderSame('Content-Type', 'application/problem+json');

        /** @var array{type: string, title: string, status: int} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('type', $body);
        $this->assertArrayHasKey('title', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertArrayNotHasKey('trace', $body);
    }

    public function test_malformed_json_returns_422(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{invalid json}'
        );

        $this->assertResponseStatusCodeSame(422);
        /** @var array{status: int} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame(422, $body['status']);
    }

    public function test_sql_injection_attempt_is_handled_safely(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            "/api/v1/accounts/'; DROP TABLE accounts; --",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer invalid']
        );

        $this->assertResponseStatusCodeSame(401);
    }
}
