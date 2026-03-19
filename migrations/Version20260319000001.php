<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create fx_rates table for FX rate persistence and caching fallback';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE fx_rates (
                id VARCHAR(36) NOT NULL,
                base_currency VARCHAR(3) NOT NULL,
                target_currency VARCHAR(3) NOT NULL,
                rate DECIMAL(20, 8) NOT NULL,
                provider VARCHAR(100) NOT NULL,
                fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT fx_rates_unique_pair UNIQUE (base_currency, target_currency, provider)
            )',
        );

        $this->addSql(
            'CREATE INDEX idx_fx_rates_pair_fetched ON fx_rates (base_currency, target_currency, fetched_at DESC)',
        );

        $this->addSql("COMMENT ON COLUMN fx_rates.fetched_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fx_rates');
    }
}
