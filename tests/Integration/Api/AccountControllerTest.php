<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountControllerTest extends WebTestCase
{
    private string $token;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Truncate tables first using bootKernel (not createClient)
        self::bootKernel();
        /** @var Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('TRUNCATE TABLE refresh_tokens, users, accounts RESTART IDENTITY CASCADE');
        self::ensureKernelShutdown();

        // Now register and get a token
        $this->client = self::createClient();
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'acc@example.com', 'password' => 'SecurePass123!', 'first_name' => 'A', 'last_name' => 'B']),
        );
        /** @var array{data: array{token: string}} $body */
        $body        = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->token = $body['data']['token'];
    }

    public function test_create_account(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"],
            (string) json_encode(['currency' => 'USD', 'name' => 'My USD']),
        );

        $this->assertResponseStatusCodeSame(201);
        /** @var array{data: array{currency: string, balance: string}} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('USD', $body['data']['currency']);
        $this->assertNotEmpty($body['data']['balance']);
    }

    public function test_list_accounts(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"],
            (string) json_encode(['currency' => 'EUR', 'name' => 'My EUR']),
        );

        $this->client->request('GET', '/api/v1/accounts', [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);

        $this->assertResponseIsSuccessful();
        /** @var array{data: list<array<string, mixed>>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($body['data']);
    }

    public function test_cannot_create_duplicate_currency_account(): void
    {
        $payload = (string) json_encode(['currency' => 'GBP', 'name' => 'GBP 1']);
        $this->client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"], $payload);
        $this->assertResponseStatusCodeSame(201);

        $payload2 = (string) json_encode(['currency' => 'GBP', 'name' => 'GBP 2']);
        $this->client->request('POST', '/api/v1/accounts', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"], $payload2);
        $this->assertResponseStatusCodeSame(409);
    }

    public function test_close_account_with_zero_balance(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"],
            (string) json_encode(['currency' => 'CAD', 'name' => 'My CAD']),
        );
        /** @var array{data: array{id: string}} $body */
        $body      = json_decode((string) $this->client->getResponse()->getContent(), true);
        $accountId = $body['data']['id'];

        $this->client->request('DELETE', "/api/v1/accounts/{$accountId}", [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        $this->assertResponseStatusCodeSame(204);
    }

    public function test_cannot_access_another_users_account(): void
    {
        // Create account as user A
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"],
            (string) json_encode(['currency' => 'JPY', 'name' => 'A JPY']),
        );
        /** @var array{data: array{id: string}} $body */
        $body      = json_decode((string) $this->client->getResponse()->getContent(), true);
        $accountId = $body['data']['id'];

        // Register user B
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'userB@example.com', 'password' => 'SecurePass123!', 'first_name' => 'B', 'last_name' => 'B']),
        );
        /** @var array{data: array{token: string}} $bodyB */
        $bodyB  = json_decode((string) $this->client->getResponse()->getContent(), true);
        $tokenB = $bodyB['data']['token'];

        // User B tries to GET user A's account
        $this->client->request('GET', "/api/v1/accounts/{$accountId}", [], [], ['HTTP_AUTHORIZATION' => "Bearer {$tokenB}"]);
        $this->assertResponseStatusCodeSame(403);

        // User B tries to PATCH user A's account
        $this->client->request(
            'PATCH',
            "/api/v1/accounts/{$accountId}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$tokenB}"],
            (string) json_encode(['name' => 'Hacked']),
        );
        $this->assertResponseStatusCodeSame(403);

        // User B tries to DELETE user A's account
        $this->client->request('DELETE', "/api/v1/accounts/{$accountId}", [], [], ['HTTP_AUTHORIZATION' => "Bearer {$tokenB}"]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function test_close_account_with_nonzero_balance_returns_409(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$this->token}"],
            (string) json_encode(['currency' => 'AUD', 'name' => 'My AUD']),
        );
        /** @var array{data: array{id: string}} $body */
        $body      = json_decode((string) $this->client->getResponse()->getContent(), true);
        $accountId = $body['data']['id'];

        // Artificially fund the account to simulate a non-zero balance
        /** @var Connection $conn */
        $conn = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('UPDATE accounts SET balance = 100 WHERE id = ?', [$accountId]);

        $this->client->request('DELETE', "/api/v1/accounts/{$accountId}", [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->token}"]);
        $this->assertResponseStatusCodeSame(409);
    }
}
