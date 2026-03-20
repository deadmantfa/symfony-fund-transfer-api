<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create transfers and audit_logs tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE transfers (
                id VARCHAR(36) NOT NULL,
                from_account_id VARCHAR(36) NOT NULL,
                to_account_id VARCHAR(36) NOT NULL,
                amount NUMERIC(24, 8) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                from_amount NUMERIC(24, 8) DEFAULT NULL,
                from_currency VARCHAR(3) DEFAULT NULL,
                to_amount NUMERIC(24, 8) DEFAULT NULL,
                to_currency VARCHAR(3) DEFAULT NULL,
                fx_rate VARCHAR(50) DEFAULT NULL,
                fx_rate_provider VARCHAR(100) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                idempotency_key VARCHAR(255) NOT NULL,
                failure_reason VARCHAR(255) DEFAULT NULL,
                initiated_by_user_id VARCHAR(36) NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT transfers_idempotency_key_unique UNIQUE (idempotency_key)
            )",
        );

        $this->addSql('CREATE INDEX idx_transfers_from_account ON transfers (from_account_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_transfers_to_account ON transfers (to_account_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_transfers_status ON transfers (status)');

        $this->addSql(
            "CREATE TABLE audit_logs (
                id VARCHAR(36) NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id VARCHAR(36) NOT NULL,
                action VARCHAR(100) NOT NULL,
                actor_id VARCHAR(36) DEFAULT NULL,
                old_value JSONB DEFAULT NULL,
                new_value JSONB DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )",
        );

        $this->addSql('CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_audit_logs_actor ON audit_logs (actor_id, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE transfers');
    }
}
