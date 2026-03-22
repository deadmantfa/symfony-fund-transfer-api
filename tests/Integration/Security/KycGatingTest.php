<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class KycGatingTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        /** @var Connection $conn */
        $conn = self::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('TRUNCATE TABLE refresh_tokens, users, accounts, transfers, ledger_entries, audit_logs RESTART IDENTITY CASCADE');
        self::ensureKernelShutdown();
    }

    public function test_unverified_user_cannot_initiate_transfer(): void
    {
        $client = self::createClient();

        // Register (KYC is unverified by default)
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'kyc@test.com', 'password' => 'SecurePass123!', 'first_name' => 'K', 'last_name' => 'Y'])
        );
        /** @var array{data: array{token: string}} $regBody */
        $regBody = json_decode((string) $client->getResponse()->getContent(), true);
        $token   = $regBody['data']['token'];

        $client->request(
            'POST',
            '/api/v1/transfers',
            [],
            [],
            [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_AUTHORIZATION'   => "Bearer {$token}",
                'HTTP_IDEMPOTENCY_KEY' => 'k1',
            ],
            (string) json_encode(['from_account_id' => 'x', 'to_account_id' => 'y', 'amount' => '10.00', 'currency' => 'USD'])
        );

        $this->assertResponseStatusCodeSame(409);
        /** @var array{title: string} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertStringContainsString('KYC', $body['title']);
    }
}
