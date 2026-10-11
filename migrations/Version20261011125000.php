<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Translate the deployed fork's settings without decrypting credentials. */
final class Version20261011125000 extends AbstractMigration
{
    public function getDescription(): string { return 'Preserve fork AI connections, encrypted headers and vector identities on upstream upgrade'; }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE ai_settings SET embedding_own_connection = NOT embedding_shared_connection');
        // Materialise the old Ollama fallback before the writing address/token
        // can be replaced by its OpenAI connection.
        $this->addSql("UPDATE ai_settings SET embedding_base_url = COALESCE(embedding_base_url, base_url) WHERE embedding_provider = 'ollama' AND NOT embedding_shared_connection");
        $this->addSql("UPDATE ai_settings SET embedding_api_token = COALESCE(embedding_api_token, api_token) WHERE embedding_provider = 'ollama' AND NOT embedding_shared_connection AND embedding_base_url = base_url");
        $this->addSql("UPDATE ai_settings SET provider = 'openai', base_url = openai_base_url, api_token = openai_api_token, chat_model = openai_model, headers = openai_headers WHERE chat_provider = 'openai'");
        // Existing fork vectors already have endpoint-hashed identities. Keep
        // their identity and width; never schedule a reindex during migration.
        $this->addSql("UPDATE ai_backfill_state SET status = 'paused', pause_reason = 'operator' WHERE status = 'running'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore a pre-upgrade backup to return to the previous connection schema.');
    }
}
