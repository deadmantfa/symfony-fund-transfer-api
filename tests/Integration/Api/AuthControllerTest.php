<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        /** @var Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('TRUNCATE TABLE refresh_tokens, users RESTART IDENTITY CASCADE');
        self::ensureKernelShutdown();
    }

    public function test_register_creates_user_and_returns_tokens(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                'email'      => 'newuser@example.com',
                'password'   => 'SecurePass123!',
                'first_name' => 'Test',
                'last_name'  => 'User',
            ]),
        );

        $this->assertResponseStatusCodeSame(201);
        /** @var array{data: array{token: string, refresh_token: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertNotEmpty($body['data']['token']);
    }

    public function test_register_with_duplicate_email_returns_409(): void
    {
        $client = self::createClient();
        $payload = (string) json_encode([
            'email' => 'dup@example.com', 'password' => 'SecurePass123!',
            'first_name' => 'A', 'last_name' => 'B',
        ]);

        $client->request('POST', '/api/v1/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/v1/auth/register', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseStatusCodeSame(409);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $client = self::createClient();

        // Register first
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'login@example.com', 'password' => 'SecurePass123!', 'first_name' => 'A', 'last_name' => 'B']),
        );

        // Login
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'login@example.com', 'password' => 'SecurePass123!']),
        );

        $this->assertResponseStatusCodeSame(200);
        /** @var array{token: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $body);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'nobody@example.com', 'password' => 'wrong']),
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function test_protected_route_without_token_returns_401(): void
    {
        $client = self::createClient();
        // Any /api path that requires IS_AUTHENTICATED_FULLY returns 401 without a JWT
        $client->request('GET', '/api/v1/me');
        $this->assertResponseStatusCodeSame(401);
    }
}
