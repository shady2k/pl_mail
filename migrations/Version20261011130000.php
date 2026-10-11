<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Durable Gmail queued and completed fetch revisions'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gmail_fetch_state (account_id INT NOT NULL REFERENCES account(id) ON DELETE CASCADE, gmail_id VARCHAR(255) NOT NULL, queued_revision NUMERIC(30,0) NOT NULL, completed_revision NUMERIC(30,0) DEFAULT NULL, reserved_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (account_id, gmail_id))');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Stop all Gmail workers before discarding active fetch reservations.');
    }
}
