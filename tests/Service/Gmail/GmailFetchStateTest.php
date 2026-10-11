<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailFetchState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GmailFetchStateTest extends KernelTestCase
{
    private Connection $db;
    private Connection $other;
    private int $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'e2e@plmail.test']);
        self::assertInstanceOf(User::class, $user);
        $account = new Account();
        $account->usr = $user;
        $account->name = 'Synthetic dedup test';
        $account->email = 'dedup@example.test';
        $account->username = 'dedup@example.test';
        $account->isActive = false;
        $account->authType = 'oauth2';
        $account->oauthProvider = 'google';
        $em->persist($account);
        $em->flush();
        $this->accountId = (int) $account->id;
        $this->db = self::getContainer()->get(Connection::class);
        $this->other = DriverManager::getConnection($this->db->getParams());
    }

    protected function tearDown(): void
    {
        $this->other->close();
        $this->db->executeStatement('DELETE FROM account WHERE id = ?', [$this->accountId]);
        parent::tearDown();
    }

    public function testOverlappingPagesAndIndependentPlannersReserveEachIdOnce(): void
    {
        $sent = [];
        $dispatch = static function (array $ids) use (&$sent): void { $sent = [...$sent, ...$ids]; };
        (new GmailFetchState($this->db))->enqueue($this->accountId, ['a', 'b', 'a'], '0', $dispatch);
        (new GmailFetchState($this->other))->enqueue($this->accountId, ['b', 'c'], '0', $dispatch);
        self::assertSame(['a', 'b', 'c'], $sent);
    }

    public function testInboxAndWholeMailboxPlannerOverlapEnqueuesOnlyNewIds(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $account = $em->find(Account::class, $this->accountId);
        $tokens = $this->createStub(\App\Service\OAuth\OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('synthetic-token');
        $http = new \Symfony\Component\HttpClient\MockHttpClient([
            new \Symfony\Component\HttpClient\Response\JsonMockResponse(['messages' => [['id' => 'a'], ['id' => 'b']]]),
            new \Symfony\Component\HttpClient\Response\JsonMockResponse(['messages' => [['id' => 'b'], ['id' => 'c']]]),
        ]);
        $messages = $this->createStub(\App\Repository\Mail\MessageRepository::class);
        $messages->method('findSyncedGmailIdsForUser')->willReturn([]);
        $sent = [];
        $bus = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$sent): \Symfony\Component\Messenger\Envelope {
            $sent[] = $message;
            return new \Symfony\Component\Messenger\Envelope($message, $stamps);
        });
        $syncer = new \App\Service\Gmail\GmailApiSyncer(
            new \App\Service\Mail\GmailApiClient($http, $tokens, new \App\Service\Gmail\GmailQuotaPacer(new \Symfony\Component\Clock\MockClock())),
            $messages, $em, $bus, new \Psr\Log\NullLogger(), $this->createStub(\App\Service\Mail\MessageEraser::class),
            new \App\Service\Mail\SyncOrigin(), new GmailFetchState($this->db),
        );
        $syncer->importInboxFirst($account);
        $syncer->backfillPage($account);
        self::assertCount(2, $sent);
        self::assertSame(['a', 'b'], $sent[0]->gmailIds);
        self::assertSame(['c'], $sent[1]->gmailIds);
        self::assertSame('0', $sent[1]->historyRevision);
        self::assertTrue($sent[1]->importQueue);
    }

    public function testDatabaseQueueInsertAndReservationRollbackTogether(): void
    {
        $before = (int) $this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'dedup_test_only'");
        try {
            (new GmailFetchState($this->db))->enqueue($this->accountId, ['a'], '0', function (): void {
                // Same connection used by the production Doctrine transport.
                $this->db->insert('messenger_messages', ['body' => '{}', 'headers' => '{}', 'queue_name' => 'dedup_test_only', 'created_at' => '2026-10-11 00:00:00', 'available_at' => '2026-10-11 00:00:00']);
                throw new \RuntimeException('Synthetic failure after transport insert');
            });
            self::fail('Dispatch must fail');
        } catch (\RuntimeException) {
            self::assertSame($before, (int) $this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'dedup_test_only'"));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM gmail_fetch_state WHERE account_id = ?', [$this->accountId]));
        }
    }

    public function testCompletedRetrySkipsFetchButLaterLabelHistoryRefreshes(): void
    {
        $state = new GmailFetchState($this->db);
        $state->complete($this->accountId, ['a'], '100');
        self::assertSame([], $state->wanted($this->accountId, ['a', 'a'], '100'));
        self::assertSame([], $state->wanted($this->accountId, ['a'], '0'));
        self::assertSame(['a'], $state->wanted($this->accountId, ['a'], '101'));
        self::assertSame(['a'], $state->wanted($this->accountId + 1, ['a'], '100'));
        // The failed subset is not completed and remains fetchable on retry.
        self::assertSame(['failed'], $state->wanted($this->accountId, ['a', 'failed'], '100'));
    }

    public function testHandlerSkipsCoveredDeliveryButFetchesLaterHistory(): void
    {
        $state = new GmailFetchState($this->db);
        $state->complete($this->accountId, ['a'], '100');
        $tokens = $this->createStub(\App\Service\OAuth\OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('synthetic-token');
        $requests = 0;
        $failure = '404 Not Found';
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function () use (&$requests, &$failure): \Symfony\Component\HttpClient\Response\MockResponse {
            ++$requests;
            $id = str_starts_with($failure, '403') ? 'b' : 'a';
            $body = 'b' === $id ? '{"error":{"errors":[{"reason":"insufficientPermissions"}]}}' : '{}';
            return new \Symfony\Component\HttpClient\Response\MockResponse("--response\r\nContent-ID: <response-$id>\r\n\r\nHTTP/1.1 $failure\r\n\r\n$body\r\n--response--\r\n");
        });
        $api = new \App\Service\Mail\GmailApiClient($http, $tokens, new \App\Service\Gmail\GmailQuotaPacer(new \Symfony\Component\Clock\MockClock()));
        $container = self::getContainer();
        $handler = new \App\Infrastructure\Messaging\Handler\SyncGmailMessageBatchHandler(
            $container->get(\App\Repository\Mail\MessageRepository::class),
            $container->get(\App\Repository\Mail\AccountRepository::class), $api,
            $container->get(\App\Service\Gmail\GmailMessageBuilder::class),
            $container->get(\App\Service\Gmail\GmailAddressFilter::class),
            $container->get(\App\Service\HarvestContactsService::class),
            $container->get(\App\Service\Mail\SyncNotifier::class),
            $container->get(\Symfony\Component\Messenger\MessageBusInterface::class),
            $container->get(\App\Service\Mail\PostIngestPipeline::class),
            $container->get(EntityManagerInterface::class),
            $container->get(\App\Jmap\State\StateManager::class), new \Psr\Log\NullLogger(),
            $container->get(\App\Service\Label\ThreadLabelSynchronizer::class),
            $container->get(\App\Service\Mail\ThreadStatusUpdater::class),
            $container->get(\App\Service\Mail\MessageCategorizer::class),
            $container->get(\App\Service\Imap\MessageThreader::class),
            $container->get(\App\Service\Mail\SyncOrigin::class), $state,
        );
        $handler(new \App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage($this->accountId, ['a', 'a'], historyRevision: '100'));
        self::assertSame(0, $requests);
        $handler(new \App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage($this->accountId, ['a'], historyRevision: '101'));
        self::assertSame(1, $requests, 'A newer Gmail label history must still reach Google');
        $handler(new \App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage($this->accountId, ['a'], historyRevision: '101'));
        self::assertSame(1, $requests, 'Redelivery of the completed revision must not repeat the request');
        $failure = '403 Forbidden';
        $handler(new \App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage($this->accountId, ['b'], historyRevision: '101'));
        self::assertSame(['b'], $state->wanted($this->accountId, ['b'], '101'), 'Permission failures must remain fetchable after authorisation is repaired');
    }

    public function testDispatchFailureRollsBackReservations(): void
    {
        $state = new GmailFetchState($this->db);
        try {
            $state->enqueue($this->accountId, ['a'], '0', static function (): void { throw new \RuntimeException('Synthetic transport failure'); });
            self::fail('Dispatch must fail');
        } catch (\RuntimeException) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM gmail_fetch_state WHERE account_id = ?', [$this->accountId]));
        }
        $sent = [];
        $state->enqueue($this->accountId, ['a'], '0', static function (array $ids) use (&$sent): void { $sent = $ids; });
        self::assertSame(['a'], $sent);
    }

    public function testExpiredReservationRecoversLostDeliveryWithoutRepeatingCompletedWork(): void
    {
        $state = new GmailFetchState($this->db);
        $state->enqueue($this->accountId, ['a', 'b'], '0', static function (): void {});
        $state->complete($this->accountId, ['a'], '0');
        $this->db->executeStatement("UPDATE gmail_fetch_state SET reserved_until = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE account_id = ?", [$this->accountId]);
        $sent = [];
        (new GmailFetchState($this->other))->enqueue($this->accountId, ['a', 'b'], '0', static function (array $ids) use (&$sent): void { $sent = $ids; });
        self::assertSame(['b'], $sent);
    }

    public function testAccountLockExcludesConcurrentHandlersAndReleasesAfterFailure(): void
    {
        $state = new GmailFetchState($this->db);
        try {
            $state->withAccountLock($this->accountId, function (): void {
                self::assertFalse($this->other->fetchOne('SELECT pg_try_advisory_lock(185071, ?)', [$this->accountId]));
                self::assertTrue($this->other->fetchOne('SELECT pg_try_advisory_lock(185071, ?)', [$this->accountId + 1]));
                $this->other->executeQuery('SELECT pg_advisory_unlock(185071, ?)', [$this->accountId + 1]);
                throw new \RuntimeException('Synthetic handler crash');
            });
        } catch (\RuntimeException) {
            self::assertTrue($this->other->fetchOne('SELECT pg_try_advisory_lock(185071, ?)', [$this->accountId]));
            $this->other->executeQuery('SELECT pg_advisory_unlock(185071, ?)', [$this->accountId]);
        }
        $this->other->executeQuery('SELECT pg_advisory_lock(185071, ?)', [$this->accountId]);
        self::assertFalse($this->db->fetchOne('SELECT pg_try_advisory_lock(185071, ?)', [$this->accountId]));
        $this->other->close(); // Worker death closes the PostgreSQL session.
        // Socket close is observed asynchronously by PostgreSQL's backend.
        // Bound the wait instead of assuming its lock disappears in this tick.
        $released = false;
        $deadline = microtime(true) + 2;
        do {
            $released = true === $this->db->fetchOne('SELECT pg_try_advisory_lock(185071, ?)', [$this->accountId]);
            if (false === $released) { usleep(10_000); }
        } while (false === $released && microtime(true) < $deadline);
        self::assertTrue($released);
        $this->db->executeQuery('SELECT pg_advisory_unlock(185071, ?)', [$this->accountId]);
    }
}
