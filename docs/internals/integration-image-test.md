# Combined All Mail / compatible-provider image test

Prepared on `test/all-mail-openai` from main `1bae047d`, combining
`feat/all-mail` and `feat/openai-provider`. The original feature branches remain
independent. Only adjacent translation additions conflicted; all keys were kept.

## Build after the architecture and local/publication choice are confirmed

The authorized local build uses `linux/arm64` on Docker Desktop. For a rebuild, set
`PLMAIL_TEST_PLATFORM` to the confirmed Docker platform, then run from this checkout:

```sh
docker build --platform "${PLMAIL_TEST_PLATFORM:?Set the confirmed Docker platform}" \
  --target frankenphp_prod \
  --build-arg APP_VERSION=test-all-mail-openai \
  --build-arg APP_COMMIT="$(git rev-parse HEAD)" \
  --build-arg APP_IMAGE=plmail-integration \
  -t plmail-integration:test-all-mail-openai .
```

No mail credentials or provider keys are required at build time. Do not create a
`v*` release tag or dispatch a publishing workflow for this test: those workflows
can publish images or move `latest`. A GHCR image/tag needs separate confirmation;
if chosen, replace the image on **all three** application services together.

## Isolated production-runtime stack, when authorized to start

First inspect configuration without starting services:

```sh
docker compose --env-file /dev/null -p plmail-image-test \
  -f compose.yaml -f compose.image-test.yaml config
```

After the local image exists and startup is approved:

```sh
docker compose --env-file /dev/null -p plmail-image-test \
  -f compose.yaml -f compose.image-test.yaml up -d php worker
```

The override pins the test tag on `php`, `worker`, and `secrets-init`; application
images have `pull_policy: never`. It uses production application settings, a new
`app_image_test` database, project-scoped Docker volumes, fresh generated test
secrets, an internal backend network, and `127.0.0.1:18017` HTTP. Only the web
container also joins a separate frontend bridge so Docker Desktop can publish
the loopback port. No source,
real database, or attachment directory is mounted. The worker, database and
GreenMail stay on the internal backend. The web frontend can technically reach
external hosts; use only synthetic accounts and mock AI settings in this stack.
`MAILER_DSN=null://null` does not disable account-specific SMTP. Upstream optional
ntfy ports are removed.

The entrypoint migrates the database automatically, including console starts.
The project name must stay `plmail-image-test`; do not substitute an existing
installation's project. `down` stops/removes this stack but preserves its volumes;
`down -v` deletes its test data and should only be used intentionally.

## Synthetic fixture preparation

Start with the empty test DB. The production image omits dev bundles and the
`app:test:*` commands refuse `APP_ENV=prod`; changing the production container to
`APP_ENV=test` is not an equivalent fixture strategy. Use the repository's verified
dev/test runtime to seed only the isolated database, with `APP_SECRET` / `APP_ENCRYPTION_KEY` loaded privately from the
shared test secrets volume. Its Doctrine test suffix must point to
`app_image_test`: a `DATABASE_URL` naming `app_image` becomes `app_image_test` in
test environment. Seed a synthetic user/admin and Gmail-style fixture messages;
never clone an operational DB. Do not run a mail worker while entering fixtures.

The combined production image was verified with two focused Chromium tests:
All Mail navigation/archive/bulk/undo and compatible-provider settings against
an internal HTTP mock. Seeded test users need production-default password hashes;
the reduced-cost test hasher otherwise triggers an authentication-upgrade mismatch.
Temporary test users are removed before handoff; create your first administrator
at `http://127.0.0.1:18017/install` using your own credentials.

## Local synthetic mail server

The override also runs `greenmail/standalone:2.1.14` with IMAP and SMTP only,
without host-published mail ports. GreenMail is a test server and does not forward
mail externally. Its mailboxes are in memory: restarting it loses the seeded mail.
Authentication is intentionally disabled on this isolated test server.

After your own administrator onboarding, add the synthetic mailbox:

- Address and username: `fixture@plmail.test`.
- IMAP: `test-mail`, port `3143`, no encryption.
- SMTP: `test-mail`, port `3025`, no encryption.
- Password: any throwaway test value; never reuse a real password.

The server was seeded with fictional Inbox, Sent, Archive, Projects, Spam, Trash
and Drafts messages plus one local SMTP delivery. A temporary plMail account
successfully synchronized nine threads. A physical IMAP copy in two folders is
represented by separate upstream threads; Gmail's multi-label deduplication is
covered by the feature tests, rather than this generic IMAP fixture.

Start all test services with the same explicit project/files:

```sh
docker compose --env-file /dev/null -p plmail-image-test \
  -f compose.yaml -f compose.image-test.yaml up -d php worker test-mail
```

No repository push, PR, registry publication or remote deployment is part of this
local test. To stop it while preserving test volumes, use the same command prefix
with `down` instead of `up -d ...`.
