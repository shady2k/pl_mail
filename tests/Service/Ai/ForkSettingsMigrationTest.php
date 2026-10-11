<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ForkSettingsMigrationTest extends KernelTestCase
{
    public function testUpgradeCopiesCiphertextAndPreservesOwnEmbeddingConnection(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            // Temp tables shadow public ones: only synthetic settings are used.
            $db->executeStatement('CREATE TEMP TABLE ai_settings (embedding_own_connection BOOLEAN, embedding_shared_connection BOOLEAN, provider TEXT, base_url TEXT, api_token TEXT, chat_model TEXT, headers TEXT, chat_provider TEXT, openai_base_url TEXT, openai_api_token TEXT, openai_model TEXT, openai_headers TEXT, embedding_base_url TEXT, embedding_api_token TEXT, embedding_headers TEXT, embedding_provider TEXT DEFAULT \'ollama\') ON COMMIT DROP');
            $db->executeStatement('CREATE TEMP TABLE ai_backfill_state (status TEXT, pause_reason TEXT) ON COMMIT DROP');
            $db->executeStatement("INSERT INTO ai_settings VALUES (FALSE,FALSE,'ollama','http://synthetic.invalid','old-cipher','old-model',NULL,'openai','https://synthetic.invalid/v1','generation-cipher','new-model','headers-cipher','http://embedding.invalid','embedding-cipher','embedding-headers-cipher')");
            $db->executeStatement("INSERT INTO ai_backfill_state VALUES ('running',NULL)");
            require_once dirname(__DIR__, 3) . '/migrations/Version20261011125000.php';
            $migration = new \DoctrineMigrations\Version20261011125000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $row = $db->fetchAssociative('SELECT * FROM ai_settings');
            self::assertSame('openai', $row['provider']);
            self::assertSame('https://synthetic.invalid/v1', $row['base_url']);
            self::assertSame('generation-cipher', $row['api_token']);
            self::assertSame('headers-cipher', $row['headers']);
            self::assertSame('embedding-cipher', $row['embedding_api_token']);
            self::assertSame('embedding-headers-cipher', $row['embedding_headers']);
            self::assertTrue($row['embedding_own_connection']);
            self::assertSame('paused', $db->fetchOne('SELECT status FROM ai_backfill_state'));
            $db->executeStatement("UPDATE ai_settings SET base_url = 'http://ollama.invalid', api_token = 'ollama-cipher', embedding_base_url = NULL, embedding_api_token = NULL");
            $migration = new \DoctrineMigrations\Version20261011125000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $row = $db->fetchAssociative('SELECT * FROM ai_settings');
            self::assertSame('http://ollama.invalid', $row['embedding_base_url']);
            self::assertSame('ollama-cipher', $row['embedding_api_token']);
        } finally {
            $db->rollBack();
        }
    }

    public function testForkBackupRetainsOllamaFallbackAndTaskHeaders(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $db = $container->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM ai_settings');
            $container->get(\App\Service\Backup\ConfigBackupDatabase::class)->restoreAiSettings([
                'chatProvider' => 'openai', 'baseUrl' => 'http://ollama.invalid', 'apiToken' => 'synthetic-ollama-token',
                'openAiBaseUrl' => 'https://generation.invalid/v1', 'openAiApiToken' => 'synthetic-openai-token',
                'openAiModel' => 'chat-model', 'openAiHeaders' => '{"X-Task":{"mode":"session"}}',
                'embeddingSharedConnection' => false, 'embeddingProvider' => 'ollama',
                'embeddingBaseUrl' => null, 'embeddingApiToken' => null,
            ]);
            $container->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
            $settings = $container->get(\App\Repository\Ai\AiSettingsRepository::class)->current();
            self::assertSame('https://generation.invalid/v1', $settings->baseUrl);
            self::assertSame('http://ollama.invalid', $settings->embeddingBaseUrl);
            self::assertSame('synthetic-ollama-token', $settings->embeddingApiToken);
            self::assertSame(['X-Task' => '{task_session_id}'], \App\Domain\Ai\ConnectionHeaders::decode($settings->headers));
        } finally {
            $db->rollBack();
        }
    }
}
