<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use Doctrine\DBAL\Connection;

/** Durable fetch coverage, shared by the live and import workers. */
final readonly class GmailFetchState
{
    // Different from the quota lock. PostgreSQL releases session locks on crash;
    // a lease alone would allow a second worker to fetch while the first slept.
    private const int LOCK_NAMESPACE = 185071;

    public function __construct(private Connection $connection) {}

    public function withAccountLock(int $accountId, callable $work): mixed
    {
        $this->connection->executeQuery('SELECT pg_advisory_lock(?, ?)', [self::LOCK_NAMESPACE, $accountId]);
        try {
            return $work();
        } finally {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, $accountId]);
        }
    }

    /**
     * Reserve and enqueue in one transaction on the Doctrine transport's DB.
     * Active retries renew the four-hour reservation. Expiry recovers a
     * terminally failed/lost delivery; completed revisions never expire.
     *
     * @param list<string> $ids
     * @param callable(list<string>): void $dispatch
     */
    public function enqueue(int $accountId, array $ids, string $revision, callable $dispatch): void
    {
        $this->withAccountLock($accountId, function () use ($accountId, $ids, $revision, $dispatch): void {
            $this->connection->transactional(function () use ($accountId, $ids, $revision, $dispatch): void {
                $reserved = [];
                foreach (array_unique($ids) as $id) {
                    $row = $this->connection->fetchOne(
                        "INSERT INTO gmail_fetch_state (account_id, gmail_id, queued_revision, reserved_until)
                         VALUES (:account, :id, :revision, CURRENT_TIMESTAMP + INTERVAL '4 hours')
                         ON CONFLICT (account_id, gmail_id) DO UPDATE
                         SET queued_revision = GREATEST(gmail_fetch_state.queued_revision, EXCLUDED.queued_revision),
                             reserved_until = EXCLUDED.reserved_until
                         WHERE (gmail_fetch_state.completed_revision IS NULL OR gmail_fetch_state.completed_revision < EXCLUDED.queued_revision)
                           AND (gmail_fetch_state.queued_revision < EXCLUDED.queued_revision OR gmail_fetch_state.reserved_until <= CURRENT_TIMESTAMP)
                         RETURNING gmail_id",
                        ['account' => $accountId, 'id' => $id, 'revision' => $revision],
                    );
                    if (false !== $row) {
                        $reserved[] = (string) $row;
                    }
                }
                if ([] !== $reserved) {
                    $dispatch($reserved);
                }
            });
        });
    }

    /** A retry keeps its revision; a later label history gets a newer one.
     * @param list<string> $ids
     * @return list<string>
     */
    public function wanted(int $accountId, array $ids, string $revision): array
    {
        $wanted = [];
        foreach (array_unique($ids) as $id) {
            $covered = $this->connection->fetchOne(
                'SELECT completed_revision >= CAST(? AS NUMERIC) FROM gmail_fetch_state WHERE account_id = ? AND gmail_id = ?',
                [$revision, $accountId, $id],
            );
            if (true !== $covered && 't' !== $covered && 1 !== $covered) {
                $this->connection->executeStatement("UPDATE gmail_fetch_state SET reserved_until = CURRENT_TIMESTAMP + INTERVAL '4 hours' WHERE account_id = ? AND gmail_id = ?", [$accountId, $id]);
                $wanted[] = $id;
            }
        }
        return $wanted;
    }

    /** Called only after persistence and post-ingest processing succeed.
     * @param list<string> $ids
     */
    public function complete(int $accountId, array $ids, string $revision): void
    {
        $this->connection->transactional(function () use ($accountId, $ids, $revision): void {
            foreach (array_unique($ids) as $id) {
                $this->connection->executeStatement(
                    "INSERT INTO gmail_fetch_state (account_id, gmail_id, queued_revision, completed_revision, reserved_until)
                     VALUES (:account, :id, :revision, :revision, CURRENT_TIMESTAMP)
                     ON CONFLICT (account_id, gmail_id) DO UPDATE
                     SET completed_revision = GREATEST(gmail_fetch_state.completed_revision, EXCLUDED.completed_revision)",
                    ['account' => $accountId, 'id' => $id, 'revision' => $revision],
                );
            }
        });
    }
}
