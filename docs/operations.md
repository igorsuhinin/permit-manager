# Maintenance and recovery

[Project overview](../README.md)

## Read-only deployment diagnostics

Run the private diagnostic command with the same configuration and PHP binary as
the deployed application:

```sh
php bin/console.php app:doctor --no-interaction
```

For local development, `make doctor` invokes it inside Docker. On cPanel, use the
provider's PHP 8.5 CLI executable and an absolute path to `bin/console.php`.
Composer and development dependencies are not required in an uploaded release.
Like the other CLI tools, this command does not load `.env` automatically.
It validates stored runtime settings, so the private initial settings file is not
required after initialization.

| Check | Inspection |
| --- | --- |
| Database | Connect and execute `SELECT 1` |
| Schema | Required application tables, columns, and named query indexes |
| Migrations | Recorded versions match the migration files in this release |
| Settings | Both signing keys, admin credentials, base URL, and timezone validate |
| Public wording | Selected PHP override is readable and passes the runtime configuration loader |
| Templates | Shared and selected private Twig templates parse without rendering or compilation-cache writes |
| Twig cache | A configured directory, or its nearest existing parent, appears writable; disabled cache needs no directory |
| Maintenance lock | The target and parent permissions allow normal runtime creation/use |
| Logger | Standard Monolog configuration loads and local file targets have suitable permissions |

Results use `OK`, `FAIL`, and `SKIP`. Exit code **0** means every performed check
passed. Exit code **1** means a check failed or the command could not start/finish.
An unavailable database fails the run and skips its three dependent checks; file
and logger checks still run. Missing or extra migration-history versions are
reported without reconciling them. In particular, old development versions from
before baseline consolidation require an explicit schema/history review.

The command loads trusted PHP configuration, parses templates, and performs only
read queries. It does not initialize migration metadata, apply migrations, seed
or reset data, rotate keys, repair permissions, create directories/cache/lock/log
files, acquire a maintenance lock, or emit a test log message. Runtime configuration
files should remain declarative, as in the supplied files. Missing optional
overrides use shared defaults; explicitly selected missing or unreadable overrides
fail even where the runtime template loader would otherwise fall back.

Output contains fixed check names and safe result messages. It does not print
database errors, credentials, key material, settings values, permit IDs, private
paths, or exception traces, including with verbose console output. Unexpected
output emitted by a configuration check is suppressed and that check fails.

Permission checks reflect the **CLI user and current mount**, not necessarily the
web PHP user. For a missing target, only the nearest existing parent's permissions
are inspected; creation is not attempted. Quotas, concurrent permission changes,
database write/commit privileges, actual lock acquisition, and log delivery still
require appropriate operational verification. PHP's error-log and stream sinks
are configured without probing delivery. Custom logger/cache implementations may
require manual inspection. Schema inspection verifies required names/read access,
not complete DDL equivalence. The command does not certify historical keys against
printed permits, every template's rendering data, TLS/proxy setup, backups, or
web-handler readiness.

Use it after explicit migration and before deployment acceptance, alongside
platform checks, HTTP smoke checks, and known legacy/HMAC permit checks. `/health`
remains an HTTP liveness response and does not perform these diagnostics. See
[deployment](deployment.md) for those additional checks.

For matching HTTP failures to local logs or optional Sentry entries, see
[request correlation and remote reporting](observability.md). Check-history and
admin-audit storage remain separate from operational logging.

## Check-history retention

The production CLI is `php bin/console.php checks:prune`. It removes only old
verification request history; it never removes issued permits, blacklist entries,
settings, audit entries, or signing keys. Apply migrations before running it. No job is installed
automatically, and the admin UI has no deletion action.

Defaults live in [config/maintenance.php](../config/maintenance.php): 90 days,
500 rows per batch, and at most 10 batches per invocation. The period is an
operational default; choose it for your deployment before enabling deletion.
Options override the file for each invocation:

```sh
make prune-checks
make prune-checks ARGS='--dry-run --days=90 --batch-size=500 --max-batches=10'
make prune-checks ARGS='--apply --days=90 --batch-size=500 --max-batches=10'
```

Without `--apply`, the command previews changes. `--apply` and `--dry-run` together
are rejected. Retention must be 1–36,500 days, batch size 1–1,000, and batch count
1–100. Thus deletion is capped at 100,000 rows per invocation. Preview counts at
most the run budget plus one row; it reports the amount this run would remove
and whether further eligible rows exist, rather than scanning an entire backlog.

The cutoff is calculated once from the injected clock in UTC. Only timestamps
strictly earlier than `now - retention days` are eligible; a row exactly at the
cutoff is retained. Each batch selects the oldest eligible rows in
`(checked_at, id)` order and deletes only those IDs with a repeated cutoff guard.
Batches commit individually. Interrupted or failed runs can leave completed
batches removed; rerunning continues safely. New request recording can continue.

Output includes the cutoff, mode, rows deleted or proposed, and whether a backlog
remains. Exit status is 0 for completion or a skipped overlap, 2 for invalid
options, and 1 for an operational failure. Completion logs contain only the
cutoff and aggregate counts. Failures log their exception type privately and
return a generic console message.

Batch limits bound database work and memory, not wall-clock time during a database
outage or lock wait. Set appropriate host/database execution limits and monitor
cron failures. A remaining backlog requires another invocation; the command does
not continue beyond its configured batch budget.

## cPanel cron

Use the provider's PHP 8.5 CLI binary and absolute paths. CLI PHP must receive the
same database configuration as web PHP. This application does not automatically
load `.env`; configure the host environment or private PHP configuration.

Set `CHECK_HISTORY_LOCK_FILE` to a stable absolute private path shared by every
release and every invocation, for example
`/home/account/shared/maintenance/check-history.lock`. The default is
`protected/maintenance/check-history.lock` within the application. Its directory
must be writable by the cron user; missing directories are created privately.
This file contains no request data. Do not unlink it while a process may hold it:
replacing its inode could allow two processes to acquire different locks.

The lock is nonblocking and released on completion, failure, or process exit. It
coordinates processes using the same file on a filesystem supporting PHP
[`flock`](https://www.php.net/manual/en/function.flock.php). It is not a
distributed lock for independent hosts/filesystems. On shared hosting, run this
job from one account/host and confirm the provider supports file locking.

Example cPanel schedule: `17 3 * * *`. Example command (replace account and PHP
paths with the provider's actual values):

```sh
CHECK_HISTORY_LOCK_FILE=/home/account/shared/maintenance/check-history.lock /usr/local/bin/php /home/account/app/current/bin/console.php checks:prune --apply --days=90 --batch-size=500 --max-batches=10 --no-interaction
```

Run the same command with `--dry-run` first. Let cPanel deliver output/failure
mail or capture it in a private, rotated log. Avoid silently discarding errors.
Choose a frequency and budget that can outpace the deployment's expired history;
initial backlogs may require multiple invocations. No daemon, queue, or additional
runtime service is needed. Local development can continue using `make prune-checks`.

## Backup contents and consistency

Keep a matched recovery set containing:

- The complete database, including `app_settings`, both signing keys, the admin
  password hash, revocations, issuance/history data, `admin_audit`, and `doctrine_migration_versions`.
- Private initial settings, wording, CSS/Twig overrides, initial blacklist files, runtime database
  configuration, maintenance settings, and the environment values configured in
  cPanel. Include files selected through custom absolute override paths.
- The verified release archive/checksum and an inventory of PHP version,
  document-root layout, override paths, and any external private-file locations.

Initial seed files are insufficient backups of live settings. A generated HMAC
key is stored in the database and the private initial settings file. Later live
settings may differ from the initial configuration. Restore existing keys exactly;
generating replacement keys invalidates printed permits.

Store backups outside `public/` and `public_html`, with access restricted to the
hosting account. Encrypt an off-host copy using your approved backup system and
keep its decryption credentials separately. Select a schedule and retention that
meet your acceptable data-loss window, and periodically rehearse restoration.
Checksums detect accidental corruption; they do not encrypt or authenticate a
backup against an attacker who can also replace its checksum file.

For a consistent database/private-file pair, pause admin writes, public history
writes, cron maintenance, and schema/configuration changes while taking the
recovery set. A transactional SQL snapshot alone does not synchronize changing
private files. On a busy service, use an appropriate provider backup facility
with documented consistency guarantees. Do not run migrations during a dump.

## SQL backup and restore example

Use the provider's backup interface/phpMyAdmin if MySQL CLI tools are unavailable.
For a CLI-capable host, create a private MySQL option file with mode `0600` that
contains the connection credentials under `[client]`; keep passwords out of shell
arguments and history. Replace all example paths and database names below.
[`mysqldump` documentation](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html)
describes required privileges and engine/version limitations. The application uses
InnoDB; `--single-transaction` does not make nontransactional tables consistent.

```sh
umask 077
mkdir -p /home/account/backups/recovery-set
mysqldump --defaults-extra-file=/home/account/private/mysql-backup.cnf \
  --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --hex-blob \
  account_permits > /home/account/backups/recovery-set/database.sql
tar -C /home/account/shared -cf /home/account/backups/recovery-set/private.tar .
```

Check each command's exit status before accepting the backup. Add the release
archive and the private configuration/environment inventory, including anything
outside `/home/account/shared`. Hash the files and verify the hashes before
restoring. The commands above create local plaintext backups; secure and encrypt
the recovery set before copying it off-host. They do not schedule backups.

Restore into a new, empty database created through cPanel, using a dedicated user
granted access to it. Restore private files into a staging private directory with
their original ownership and permissions. Do not import into the live database
for a rehearsal, and do not run the initial seed or local reset command.

```sh
mysql --defaults-extra-file=/home/account/private/mysql-restore.cnf \
  account_permits_restored < /home/account/backups/recovery-set/database.sql
tar -C /home/account/restored-private -xpf /home/account/backups/recovery-set/private.tar
```

Inspect the archive's members before extraction and ensure the destination exists.
Use the release matching the backup schema. Restore the saved database/environment
configuration and override paths, pointing an isolated application instance to
the restored database and private files. Do not expose the rehearsal to public
traffic. Run platform checks and inspect migration status before making any
schema change; retain the restored migration history.

## Recovery verification and cutover

Before serving traffic:

1. Compare all table contents/counts with the backup inventory and confirm the
   original legacy/HMAC signing keys and administrator credentials were restored.
   Compare keys privately; do not print them into diagnostics or screenshots.
2. Delete rows from `admin_sessions` and `admin_login_attempts` in the **restored**
   database to invalidate restored login state. This does not rotate credentials
   or either signing key.
3. Run platform checks and HTTP smoke checks against the restored instance. Test
   pre-backup resident/guest links for both signature versions, an unrecorded
   legacy permit, and a revoked ID. Use private check fixtures as described in
   [deployment](deployment.md#http-smoke-checks). Compare private public wording
   and permit/CSS overrides as well.
4. Sign in, review permits/history/blacklist, test printing/reprinting using
   synthetic records, and preview maintenance. Remove rehearsal-only data before
   cutover. Confirm existing permits need no regenerated signatures.
5. Reconcile any writes since the backup, including new revocations and issued
   permits. Restoring older revocations can approve a subsequently revoked ID;
   old settings can restore superseded credentials. Establish the accepted data
   loss explicitly and keep the original database available until cutover is verified.
6. Point the intended web/CLI configuration to the recovered database and private
   files, refresh PHP caches as required, repeat smoke checks, then resume traffic
   and cron. Keep the maintenance lock path stable across releases.

`make release-smoke` rehearses a SQL dump/import and private-file loss/restoration
using only synthetic data in disposable containers. It verifies exact table/file
contents, migration metadata, credentials and both keys, then switches Apache to
the recovered database and checks pre-backup legacy/HMAC links and the production
maintenance CLI. It also exercises code rollback afterward. This test requires
Docker only in development/CI; the production procedure uses ordinary PHP,
MySQL/cPanel tools, and cron. No production backup or cron configuration is
changed automatically.

## Admin audit trail

Audit retention is **indefinite by default**. Neither `checks:prune` nor log-file
rotation removes audit rows. The application provides append-only writes; it has
no audit edit/delete action. This is an operational history, not tamper-proof
storage: a privileged database operator, schema rollback, or older application
release can remove records or create gaps. Include audit rows in every database
backup. The destructive local reset clears them only in its isolated local-data
workflow.

Before adopting a different retention period, define and document the deployment
policy, archive the affected rows privately, verify the archive can be restored,
and perform any removal under controlled database maintenance. No automatic
expiry or audit-retention job is installed by this release. Audit entries begin
with deployment; no historical administrator activity is invented or backfilled.

Stored fields are actor username (from trusted authenticated server configuration),
action, affected-record hash, UTC timestamp, and an audit-row ID. Actions are
`permit_issued`, `blacklist_added`, `blacklist_removed`, `login`, and `logout`.
Targets are the issuance fingerprint, exact-ID SHA-256 hash, or admin username
hash respectively. No passwords, password hashes, session/CSRF tokens, QR
signatures/URLs, signing keys, client addresses, permit claims, or blacklist reasons
are stored in this table. Hashes allow correlation and can be guessed for small
ID spaces; keep the table and backups private.

Use your private database access to inspect the latest entries:

```sql
SELECT id, actor, action, target_hash, occurred_at
FROM admin_audit
ORDER BY id DESC
LIMIT 100;
```

The audit trail covers committed state changes and successful authentication.
Validation failures, duplicate issuance, duplicate blacklist additions, no-op
removals, reprints, page views, and failed/throttled login attempts do not append
change entries. Existing operational logs/counters cover those authentication
failures. Reprinting remains a read workflow. Direct SQL edits, initial seeding,
and historical releases are outside this application audit boundary.

## Failure and retry behavior

| Failure point | Stored outcome | Recovery |
| --- | --- | --- |
| Validation or database write fails before commit | No new issuance/change or audit entry | Resolve the cause and retry |
| Required audit insert fails | The associated data/session change rolls back | Restore audit storage, then retry |
| QR generation, print rendering, or operational logging fails after issuance commit | Issuance and audit entry remain; the HTTP response is a generic 500 | Retry identical claims with unchanged signing configuration, or reprint the saved valid permit |
| HTTP/network delivery fails after commit | The caller may not know whether the operation committed | Inspect the current state before retrying |

A connection failure during commit can leave its outcome unknown to the caller.
Inspect the current state; retrying identical issuance is deduplicated.

Identical permit claims/version have one database fingerprint. Concurrent or
repeated issuance preserves the winner's timestamp and appends only its single
issuance audit entry. Reprints keep the stored signature version. Changing claims
creates another issuance; changing signing keys or the base URL can change a
regenerated QR target and is outside the retry guarantee. Current expiry and
revocations still govern public verification and reprinting.

A blacklist write can likewise commit before a logging/delivery error. Inspect
the list: retrying an already-added ID returns the existing duplicate conflict;
removing an absent ID is harmless and adds no audit entry. Concurrent add/remove
operations are ordered by database locks; whichever operation commits last
controls the resulting state. A record's audit rows reflect the successful
changes, without promising a universal response-completion order across users.

Login/logout session changes and their audit records are transactional. An error
after commit can leave a new login session without a delivered cookie, or a
logout completed despite an error response. Refresh/sign in again as appropriate;
server-side session expiry still applies. Failed-login counters use atomic
increments/window resets. Attempts already admitted before concurrent failures
reach the threshold may finish; subsequent checks observe the updated count.
Successful login clears the address counter when its transaction commits.

Database outages fail verification closed with a generic 500; they never approve
a permit using an empty blacklist. Private logs retain diagnostics. Check-history
storage remains best-effort and cannot record an outage in the same unavailable
database. Losing only audit storage blocks audited admin writes/authentication;
public verification does not depend on the audit table.

Do not infer success solely from an audit-row timestamp or an error solely from
the response: audit rows describe database commits, not receipt of a printable
page or an exactly-once HTTP exchange. No message broker, automatic command
replay, or background retry queue is involved. Application rollback must retain
audit support if a continuous trail is required; deploying an older writer can
otherwise introduce unaudited changes.
