<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TransferControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('TRUNCATE TABLE refresh_tokens, users, accounts, transfers, ledger_entries, audit_logs RESTART IDENTITY CASCADE');
        static::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    private function registerAndVerifyUser(string $email): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => $email, 'password' => 'SecurePass123!', 'first_name' => 'A', 'last_name' => 'B']),
        );
        /** @var array{data: array{token: string}} $body */
        $body  = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $body['data']['token'];

        /** @var Connection $conn */
        $conn = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            "UPDATE users SET kyc_status = 'verified', email_verified_at = NOW() WHERE email = ?",
            [$email]
        );

        return $token;
    }

    private function createAccount(string $token, string $currency = 'USD'): string
    {
        $this->client->request(
            'POST',
            '/api/v1/accounts',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"],
            (string) json_encode(['currency' => $currency, 'name' => "My {$currency}"]),
        );
        /** @var array{data: array{id: string}} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        return $body['data']['id'];
    }

    public function test_initiate_transfer_returns_202(): void
    {
        $tokenA       = $this->registerAndVerifyUser('transfer_a@example.com');
        $fromAccountId = $this->createAccount($tokenA, 'USD');

        // Register user B for destination account
        $tokenB       = $this->registerAndVerifyUser('transfer_b@example.com');
        $toAccountId   = $this->createAccount($tokenB, 'USD');

        // Fund source account
        /** @var Connection $conn */
        $conn = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('UPDATE accounts SET balance = ? WHERE id = ?', ['500.00000000', $fromAccountId]);

        $this->client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_AUTHORIZATION'   => "Bearer {$tokenA}",
                'HTTP_IDEMPOTENCY_KEY' => 'idem-test-' . uniqid(),
            ],
            (string) json_encode([
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'amount'          => '50.00',
                'currency'        => 'USD',
            ])
        );

        $this->assertResponseStatusCodeSame(202);
        $this->assertNotEmpty($this->client->getResponse()->headers->get('Location'));

        /** @var array{data: array{status: string, id: string}} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('pending', $body['data']['status']);
        $this->assertNotEmpty($body['data']['id']);
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $tokenA       = $this->registerAndVerifyUser('transfer_c@example.com');
        $fromAccountId = $this->createAccount($tokenA, 'USD');
        $tokenB       = $this->registerAndVerifyUser('transfer_d@example.com');
        $toAccountId   = $this->createAccount($tokenB, 'USD');

        $this->client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$tokenA}",
                // No Idempotency-Key header
            ],
            (string) json_encode([
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'amount'          => '10.00',
                'currency'        => 'USD',
            ])
        );

        $this->assertResponseStatusCodeSame(422);
    }

    public function test_unverified_user_cannot_initiate_transfer(): void
    {
        // Register without verifying KYC
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'transfer_unv@example.com', 'password' => 'SecurePass123!', 'first_name' => 'X', 'last_name' => 'Y']),
        );
        /** @var array{data: array{token: string}} $body */
        $body  = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $body['data']['token'];

        $this->client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_AUTHORIZATION'   => "Bearer {$token}",
                'HTTP_IDEMPOTENCY_KEY' => 'k-unv-' . uniqid(),
            ],
            (string) json_encode([
                'from_account_id' => 'x',
                'to_account_id'   => 'y',
                'amount'          => '10.00',
                'currency'        => 'USD',
            ])
        );

        $this->assertResponseStatusCodeSame(409);
        /** @var array{title: string} $respBody */
        $respBody = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('KYC', $respBody['title']);
    }

    public function test_duplicate_idempotency_key_returns_200(): void
    {
        $tokenA       = $this->registerAndVerifyUser('transfer_e@example.com');
        $fromAccountId = $this->createAccount($tokenA, 'USD');
        $tokenB       = $this->registerAndVerifyUser('transfer_f@example.com');
        $toAccountId   = $this->createAccount($tokenB, 'USD');

        /** @var Connection $conn */
        $conn = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('UPDATE accounts SET balance = ? WHERE id = ?', ['500.00000000', $fromAccountId]);

        $key = 'dup-idem-' . uniqid();

        // First request
        $this->client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_AUTHORIZATION'   => "Bearer {$tokenA}",
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ],
            (string) json_encode([
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'amount'          => '20.00',
                'currency'        => 'USD',
            ])
        );
        $this->assertResponseStatusCodeSame(202);
        /** @var array{data: array{id: string}} $firstBody */
        $firstBody = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Second request with same key
        $this->client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_AUTHORIZATION'   => "Bearer {$tokenA}",
                'HTTP_IDEMPOTENCY_KEY' => $key,
            ],
            (string) json_encode([
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'amount'          => '20.00',
                'currency'        => 'USD',
            ])
        );
        $this->assertResponseStatusCodeSame(200);
        /** @var array{data: array{transfer_id: string}} $secondBody */
        $secondBody = json_decode((string) $this->client->getResponse()->getContent(), true);
        // First response: data.id, second response (cached): data.transfer_id
        $this->assertSame($firstBody['data']['id'], $secondBody['data']['transfer_id']);
    }

    public function test_cannot_view_another_users_transfer(): void
    {
        $tokenA       = $this->registerAndVerifyUser('transfer_g@example.com');
        $fromAccountId = $this->createAccount($tokenA, 'USD');
        $tokenB       = $this->registerAndVerifyUser('transfer_h@example.com');
        $toAccountId   = $this->createAccount($tokenB, 'USD');

        /** @var Connection $conn */
        $conn = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('UPDATE accounts SET balance = ? WHERE id = ?', ['500.00000000', $fromAccountId]);

        // User A creates a transfer
        $this->client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_AUTHORIZATION'   => "Bearer {$tokenA}",
                'HTTP_IDEMPOTENCY_KEY' => 'view-test-' . uniqid(),
            ],
            (string) json_encode([
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'amount'          => '10.00',
                'currency'        => 'USD',
            ])
        );
        $this->assertResponseStatusCodeSame(202);
        /** @var array{data: array{id: string}} $createBody */
        $createBody = json_decode((string) $this->client->getResponse()->getContent(), true);
        $transferId = $createBody['data']['id'];

        // User B tries to view User A's transfer
        $this->client->request(
            'GET',
            "/api/v1/transfers/{$transferId}",
            [],
            [],
            ['HTTP_AUTHORIZATION' => "Bearer {$tokenB}"]
        );
        $this->assertResponseStatusCodeSame(403);
    }
}
