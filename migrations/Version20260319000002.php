<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users and refresh_tokens tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE users (
                id VARCHAR(36) NOT NULL,
                email VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                password_hash VARCHAR(512) NOT NULL,
                phone_number VARCHAR(30) DEFAULT NULL,
                date_of_birth DATE DEFAULT NULL,
                address JSONB DEFAULT NULL,
                kyc_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT users_email_unique UNIQUE (email)
            )",
        );

        $this->addSql(
            "CREATE INDEX idx_users_email ON users (email)",
        );

        $this->addSql(
            "CREATE TABLE refresh_tokens (
                id SERIAL PRIMARY KEY,
                refresh_token VARCHAR(128) NOT NULL,
                username VARCHAR(255) NOT NULL,
                valid TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT refresh_tokens_token_unique UNIQUE (refresh_token)
            )",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE users');
    }
}
