<?php

declare(strict_types=1);

use App\Application\Transfer\DuplicateIdempotencyKeyException;
use App\Application\Transfer\InitiateTransferCommand;
use App\Application\Transfer\InitiateTransferHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdempotencyTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement('TRUNCATE TABLE refresh_tokens, users, accounts, transfers, ledger_entries, audit_logs RESTART IDENTITY CASCADE');
    }

    public function test_same_idempotency_key_raises_exception_on_second_call(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');

        // Seed a verified user
        $userId = (string) \App\Domain\Shared\ValueObject\Uuid::generate();
        $conn->executeStatement(
            "INSERT INTO users (id, email, first_name, last_name, password_hash, kyc_status, status, email_verified_at, created_at, updated_at)
             VALUES (?, 'idm@test.com', 'I', 'D', 'hash', 'verified', 'active', NOW(), NOW(), NOW())",
            [$userId]
        );

        // Seed two accounts
        $fromId  = (string) \App\Domain\Shared\ValueObject\Uuid::generate();
        $toId    = (string) \App\Domain\Shared\ValueObject\Uuid::generate();
        $userId2 = (string) \App\Domain\Shared\ValueObject\Uuid::generate();
        $conn->executeStatement(
            "INSERT INTO users (id, email, first_name, last_name, password_hash, kyc_status, status, email_verified_at, created_at, updated_at)
             VALUES (?, 'idm2@test.com', 'I', 'D2', 'hash', 'verified', 'active', NOW(), NOW(), NOW())",
            [$userId2]
        );
        $conn->executeStatement(
            "INSERT INTO accounts (id, user_id, currency, balance, name, status, created_at, updated_at)
             VALUES (?, ?, 'USD', '200.00000000', 'From', 'active', NOW(), NOW())",
            [$fromId, $userId]
        );
        $conn->executeStatement(
            "INSERT INTO accounts (id, user_id, currency, balance, name, status, created_at, updated_at)
             VALUES (?, ?, 'USD', '0.00000000', 'To', 'active', NOW(), NOW())",
            [$toId, $userId2]
        );

        /** @var InitiateTransferHandler $handler */
        $handler = static::getContainer()->get(InitiateTransferHandler::class);

        $key     = 'idempotency-test-' . uniqid();
        $command = new InitiateTransferCommand(
            userId: $userId,
            fromAccountId: $fromId,
            toAccountId: $toId,
            amount: '10.00',
            currency: 'USD',
            idempotencyKey: $key,
            ipAddress: '127.0.0.1',
            userAgent: 'test',
        );

        $handler($command);

        $this->expectException(DuplicateIdempotencyKeyException::class);
        $handler($command);
    }
}
