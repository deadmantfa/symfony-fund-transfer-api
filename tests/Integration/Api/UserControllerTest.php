<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
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

    public function test_get_my_profile(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'me@test.com', 'password' => 'SecurePass123!', 'first_name' => 'Me', 'last_name' => 'Test'])
        );
        /** @var array{data: array{token: string}} $reg */
        $reg   = json_decode((string) $client->getResponse()->getContent(), true);
        $token = $reg['data']['token'];

        $client->request('GET', '/api/v1/users/me', [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $this->assertResponseIsSuccessful();
        /** @var array{data: array{email: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('me@test.com', $body['data']['email']);
        $this->assertArrayNotHasKey('password_hash', $body['data']);
    }

    public function test_gdpr_export_returns_all_user_data(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'export@test.com', 'password' => 'SecurePass123!', 'first_name' => 'Ex', 'last_name' => 'Port'])
        );
        /** @var array{data: array{token: string}} $reg */
        $reg   = json_decode((string) $client->getResponse()->getContent(), true);
        $token = $reg['data']['token'];

        $client->request('GET', '/api/v1/users/me/export', [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $this->assertResponseIsSuccessful();
        /** @var array{data: array{user: mixed, accounts: mixed, transfers: mixed}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('user', $body['data']);
        $this->assertArrayHasKey('accounts', $body['data']);
        $this->assertArrayHasKey('transfers', $body['data']);
    }

    public function test_delete_account_closes_user(): void
    {
        $client = self::createClient();
        $client->request(
            'POST',
            '/api/v1/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['email' => 'delete@test.com', 'password' => 'SecurePass123!', 'first_name' => 'Del', 'last_name' => 'Me'])
        );
        /** @var array{data: array{token: string}} $reg */
        $reg   = json_decode((string) $client->getResponse()->getContent(), true);
        $token = $reg['data']['token'];

        $client->request('DELETE', '/api/v1/users/me', [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}"]);

        $this->assertResponseStatusCodeSame(204);
    }
}
