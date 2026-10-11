# Fork upgrade and Gmail fetch deduplication

This branch starts at upstream `99d2da3da2abe243a6259099f2fa2678c31c1acb`
and integrates the deployed fork `31346f347d65ee031f87429d2a018c580ba7fa13`.
The earlier validation base was `92116fcdcbfe3af3226519f66349683096324245`.
The upstream unread-tab migration owns `Version20261011130000`; the fork dedup
migration uses `Version20261011140042` to avoid that identity collision.
It is a local candidate, not an authorised production deployment.

## Compatibility

| Capability | Integration |
| --- | --- |
| Account and unified All Mail | Retained fork navigation, queries and actions, including Sent; Spam and Trash remain excluded. |
| Gmail import | Upstream Inbox-first and one-page-at-a-time importer; dedicated `ingest_backlog` transport and `worker-backlog`. |
| Gmail quota | Retained DB-shared limiter keyed by Gmail user and Google project. Default 6,000 units/minute with 20% headroom; `messages.get` costs 20; maximum 50 per batch. |
| AI providers and connections | Upstream OpenAI-compatible/Ollama clients, settings forms and separate search connection. No duplicate legacy AI client. |
| Stored credentials and headers | Fork ciphertext copied to upstream columns without decrypting it. Existing encryption key must remain unchanged. Static headers remain encrypted. |
| Task headers | Legacy session mode translates to `{task_session_id}`. The whole-value marker resolves to one opaque task ID; Messenger stamp persists across retries. Backfill chunks share the run ID. |
| Existing embeddings | Retain fork `v1:` identity derived from provider, endpoint, model and revision. Token rotation does not change the space. Endpoint/model changes require approval; unexpected width creates a new revision and blocks indexing. |
| Summary cache | Retain the fork's provider/address/model cache identity, so cached summaries survive this upgrade and are invalidated on a connection change. |
| AI backfill | Migration pauses running walks. SQL updates fence the original run and live status, including changes during HTTP; continuations retain the original run/space. Resume rotates the generation while retaining cursors. Generation and space tags fence obsolete queued chunks. Approval does not start an index; starting/resuming remains an explicit operator action. |
| Configuration backups | Both upstream format and the deployed fork's connection/header fields are accepted. |

## Gmail fetch coverage

`gmail_fetch_state` stores one row per carrier account and Gmail ID, with queued
and completed history revisions. Initial import has revision `0`; incremental
changes carry the response's history ID. A completed revision is skipped before
HTTP, but a later label change still fetches and enriches the existing message.
Only successful persistence/post-ingest work and confirmed gone messages are
completed; failed subsets remain eligible for bounded retries.

Planning reserves IDs and dispatches envelopes within one DBAL transaction.
The Messenger Doctrine transport must use the same database connection for this
atomicity. A four-hour reservation expires if a delivery is lost; active retries
renew it. Completed coverage persists until the account is deleted. The table
grows with the mailbox and needs ordinary database capacity monitoring.
Expiry only makes a later planner enqueue eligible again; it does not create a
delivery by itself. Messenger redelivery normally recovers a worker crash. A
terminally failed/lost job that is never listed again requires an explicitly
reviewed recovery operation. Permission failures are not completed as gone.

PostgreSQL session advisory locks serialize planners and fetch handlers for an
account, while other accounts remain independent. Session close/crash releases
the lock. **A direct/session-pooled PostgreSQL connection is required; transaction
pooling such as PgBouncer transaction mode is incompatible.** The existing quota
lock uses a different namespace. The account lock covers network work as well as
completion, preventing two handlers from fetching the same revision concurrently.
Consequently an import batch can delay a live change while it finishes or waits
for quota; splitting batches at 50 bounds that work, but does not eliminate the wait.

Persistence before completion remains at-least-once: a crash in that interval
can cause one re-fetch. Database row enrichment remains idempotent. Production
legacy envelopes have no revision and conservatively refresh at the current
cursor instead of dropping stored IDs. If the cursor advances, another legacy
delivery can refresh again. This patch does not claim to remove every existing
duplicate from an old queue.

## Safe diagnostics

Inner batch errors log only aggregate status/allowlisted reason, method,
account number and counts; unfamiliar reasons become `other`. Local limiter
holds log `source=local`, `reason=localCooldown` and retry delay before HTTP.
Watch-registration failures use the same distinction, including the UI message
that Google was not called. No message bodies, IDs, tokens or endpoint credentials
are added to these logs.

## Migration and rollout proposal

No production operation is performed by this document or the local tests.

1. Review the candidate diff and verify the actual deployed SHA/digest again.
   Take a database/configuration backup while retaining the existing encryption
   key through the established secret mechanism. Do not print credentials.
2. Rehearse on a restored, isolated database. The three fork migration identities
   remain present, so already-applied migrations are not repeated. Upstream's
   separate-search migration tolerates existing fork columns. The compatibility
   migration maps chat settings, preserves embedding ciphertext and pauses AI
   walks; the final migration adds the Gmail coverage table.
3. Verify settings metadata, existing vector-space identity/dimensions and row
   counts before/after. Review generated schema differences; obsolete fork columns
   intentionally remain for compatibility and are not automatically removed.
4. If deployment is approved, stop old consumers during migrations and start all
   processes of the candidate together. The upstream `app:work` supervisor includes
   `worker-backlog`; deployments starting explicit individual consumers must add
   `ingest_backlog`. Verify direct/session-pooled DB connectivity and shared
   Doctrine transport connection before starting handlers.
5. Observe safe method/status counters, queue age, completion growth, watch
   expiration/renewal and local-versus-remote throttling. A rollback requires a
   coordinated image/database restore; the vector/connection migrations are
   deliberately irreversible. Avoid mixing old and new workers.

Existing production queue cleanup is a separate proposed operation: first take
a read-only bounded snapshot, distinguish tagged revisions from ambiguous legacy
refreshes, and review a dry-run plan. Quiescing workers, deleting/replacing queued
jobs, resuming abandoned jobs and registering a watch all need separate approval.
No purge or retry is included here.

## Local validation

The isolated Compose project is `plmail_dedup_test`, with HTTP on port 8016.
PHPUnit uses a separate `app_test_unit` database; browser tests use synthetic E2E
accounts. Gmail HTTP and AI migration credentials in new regressions are synthetic.
No production database or real Gmail API is used by these tests.

New regressions cover overlapping planners/Inbox pages, reservation and queue
rollback, failed subsets, expired reservations, independent connection locks,
actual handler redelivery with zero HTTP calls, later history refresh,
local-cooldown diagnostics, sanitised inner batch errors, encrypted-setting
compatibility, task/retry IDs and embedding-space isolation. PostgreSQL regressions
also replace/pause a run while its old chunk is in flight, exercise the real
Doctrine transport rollback, decode a pre-fields PHP envelope, and refresh
read/star/labels with a synthetic 200 response at a newer history revision.

An additional rehearsal created `app_test_upgrade_fixture` from the complete
migration directory at deployed SHA `31346f347d65ee031f87429d2a018c580ba7fa13`,
seeded only synthetic user/account/message/settings/vector rows, and applied
this candidate's remaining migrations. Nine migrations completed, including
all six new October 11 versions. SQL checks confirmed exact ciphertext/header
retention, separate embedding connection, approved-space/width/vector row
retention, paused old AI run, and the new dedup table. This is a historical-schema
rehearsal; an actual production backup restore remains a prerequisite to rollout.

## Things that bite

- Do not mix old and new consumers or run advisory locks through transaction pooling.
- Keep the encryption key unchanged during an in-place upgrade.
- Old revisionless envelopes need conservative handling; do not purge them by ID alone.
- Connection/width approval does not start a backfill. Review it separately.
- Rollback requires the pre-upgrade database backup; migration down is not a rollback plan.
