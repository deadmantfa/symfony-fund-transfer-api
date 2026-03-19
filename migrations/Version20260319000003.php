<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts and ledger_entries tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE accounts (
                id VARCHAR(36) NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                name VARCHAR(100) NOT NULL,
                balance NUMERIC(24, 8) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT accounts_user_currency_active UNIQUE (user_id, currency) DEFERRABLE INITIALLY DEFERRED
            )",
        );

        $this->addSql(
            'CREATE INDEX idx_accounts_user_id ON accounts (user_id)',
        );

        $this->addSql(
            'CREATE TABLE ledger_entries (
                id VARCHAR(36) NOT NULL,
                transfer_id VARCHAR(36) NOT NULL,
                account_id VARCHAR(36) NOT NULL,
                entry_type VARCHAR(10) NOT NULL,
                amount NUMERIC(24, 8) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                balance_before NUMERIC(24, 8) NOT NULL,
                balance_after NUMERIC(24, 8) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )',
        );

        $this->addSql(
            'CREATE INDEX idx_ledger_account_created ON ledger_entries (account_id, created_at DESC)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ledger_entries');
        $this->addSql('DROP TABLE accounts');
    }
}
