# Database and permit compatibility

[Project overview](../README.md)

## Schema and migrations

The application uses concrete Doctrine DBAL adapters implementing domain and
application persistence/query interfaces, without an ORM.
Doctrine Migrations is configured in [config/cli-config.php](../config/cli-config.php).

The consolidated baseline
[Version20261105000000](../migrations/Version20261105000000.php) creates:

| Table                  | Data                                                                    |
|------------------------|-------------------------------------------------------------------------|
| `app_settings` | Runtime configuration, including legacy/HMAC signing keys and admin password hash |
| `blacklisted_ids`      | Revoked permit IDs with optional reasons                                |
| `permits`              | Newly issued permits and their original issuance timestamps             |
| `admin_sessions`       | Expiring sessions, hashed session tokens, and CSRF tokens               |
| `admin_login_attempts` | Hashed client addresses and login failure counters                      |
| `permit_checks`        | Public verification request history                                     |
| `admin_audit`          | Committed admin actions with actor, target hash, and UTC timestamp        |

Check history includes nullable `expires_on` and `apartment` columns for the
details submitted with each check. Missing details remain null. These columns are
part of the consolidated baseline; there is still a single migration for the
first production deployment.

Doctrine tracks applied versions in `doctrine_migration_versions`. Migrations run
explicitly, never during requests:

```sh
make migrate
make migration-status
make migration
```

The single baseline includes `permits.signature_version` (defaulting to legacy
version `1`), both signing keys, all maintenance/listing indexes, and the audit
schema. New admin issuance explicitly stores version `2`. Initial settings and
private blacklist data are validated before any SQL is queued. Permits, check
history, audit entries, sessions, and login counters start empty; historical
activity is not backfilled.

`make migration` generates a blank migration; implement its SQL/schema changes
before applying it. Add a new migration for future changes instead of editing an
applied baseline.

The baseline targets a **fresh application database**. Earlier development
migrations have been removed; this is not an upgrade path for databases carrying
those versions, including the former four-migration development sequence.
A disposable old development database can be recreated using the current baseline.
`make reset-db` resets data only and does not reconcile old migration metadata.
Do not delete production data or mark a migration applied without verifying its actual schema and seed requirements.

MySQL schema DDL commits implicitly. This baseline is nontransactional; a partial
schema failure may require manual recovery. Rolling it down drops all seven
application tables and their data, including both signing keys and the audit trail.
It is a destructive schema teardown, even when issued permits exist. Prefer a
compatible application-code rollback and back up before schema changes.

## Initial data

[config/initial-data.php](../config/initial-data.php) validates initial configuration
before the baseline queues its SQL. Run `make setup` first to create
`protected/initial-settings.php`. The file contains all six initial values:
admin username, password hash, legacy signing secret, independent HMAC key, base
URL, and timezone. Defaults are `admin`, generated credentials/keys,
`http://localhost:8080`, and `Europe/Riga`. See
[initial setup](getting-started.md#configure-initial-values) for customization.
`INITIAL_SETTINGS_CONFIG` selects another private file path, relative to the
application root unless absolute. Missing or invalid settings fail before schema
SQL is queued. The command does not read or update the database.

For initial revocations:

```sh
mkdir -p protected
cp config/initial-blacklist.example.php protected/initial-blacklist.php
```

Edit the private file to return a list of exact permit IDs, for example:

```php
<?php

return ['DEMO-001', 'DEMO-002'];
```

`INITIAL_BLACKLIST_CONFIG` selects an alternative path, resolved from the project
root unless absolute. Without an explicit path, a missing
`protected/initial-blacklist.php` means an empty initial blacklist. An explicit
missing file fails initialization. Set the path explicitly in production to
catch an omitted upload. The list must contain unique, non-empty strings.

The seed runs once when the baseline is applied. Editing private initial settings
does not update an initialized database. Running `make migrate` again does
not restore entries removed through the admin panel. Manage live blacklist data
through the admin UI and runtime settings through the database.

## Existing permits

Verification checks the current blacklist, expiration, and selected signature version. It
requires **no issuance row** in `permits`, so old printed permits need no import
or backfill. Checking one creates a `permit_checks` history row, not a `permits`
issuance record. Deleting an issuance record would not revoke the corresponding
QR code; add its exact ID to the blacklist.

Compatibility depends on retaining the **original signing secret**. The admin
password hash is separate and can change without invalidating QR codes. The
legacy signature format remains MD5 with `hash_equals()` comparison:

| Permit   | Signed concatenation, without separators           |
|----------|----------------------------------------------------|
| Resident | `plate + id + term + signing_secret`               |
| Guest    | `apartment + id + term + signing_secret + "guest"` |

New generation uppercases plates. Verification retains the plate value supplied
in the QR query. Expiration must be an actual date in `dd.mm.yyyy` format and is
valid through `23:59:59` in the configured database timezone. A blacklist match
takes precedence over expiration errors, which take precedence over signature
failure. Invalid or malformed input produces an invalid-permit page.

The exact ID is matched case-sensitively using a SHA-256 key, independently of
MySQL collation. Blacklisting an ID affects all its renewals, vehicles, and
resident/guest variants. An apartment number is not an apartment-wide rule.

New issuance and admin blacklist changes share the same ID rules: the ID must
contain non-whitespace content, be at most 255 bytes, and contain no ASCII control
characters (`0x00`–`0x1f` or `0x7f`). IDs are preserved exactly, including surrounding
spaces; the limit counts UTF-8 bytes rather than displayed characters. This keeps
newly issued IDs manageable through the blacklist. These issuance rules do not
apply to public verification or reprinting existing records, so legacy signed
IDs outside these limits retain their existing verification behavior.

New issuance records are saved before the printable response is returned.
Submitting identical permit data again preserves the original row and timestamp;
a SHA-256 fingerprint of the complete permit data and signature version identifies duplicates. Different
permit data can share an ID. Verification does not fall back to an empty blacklist
when the database fails; it returns a generic server error.

## Signature versions

| QR query | Algorithm | Signing key |
| --- | --- | --- |
| No `v`, empty `v`, or `v=1` | Original MD5 concatenation above | `signing_secret` |
| `v=2` | HMAC-SHA-256, lowercase hexadecimal digest | Decoded `hmac_signing_key` |

All new admin issuance uses version 2, regardless of submitted version fields.
Unknown versions and non-string version values are malformed requests. A specified
version is never inferred from the signature length or retried under another
algorithm. Removing/changing `v` from a version 2 URL invalidates that HMAC signature.
Previously printed, unversioned links continue to use the original verifier/key,
even without an issuance record. Both public verification paths support both formats.

Version 2 signs this fixed sequence of raw string fields:

```text
parking-permit, 2, resident|guest, id, term, plate|apartment
```

The resident identity is its plate; the guest identity is its apartment. Each
field is encoded as its decimal **byte length**, then `:`, then its exact bytes.
The encodings are concatenated with no other separators and passed to HMAC-SHA-256
using the 32-byte binary key. This authenticates the purpose, version, permit type,
and claims with unambiguous boundaries, including Unicode, colons, and spaces.
Query parameter order and URL encoding are not signed; decoded values are signed.
No trimming or Unicode normalization occurs. Issuance still uppercases plates
before signing; verification uses exact supplied values. Comparisons use `hash_equals()`.

`hmac_signing_key` stores 64 hex characters. Setup generates 32 cryptographically
random bytes once, or accepts an existing key through a hidden prompt. The
baseline copies it from private initial settings and never derives it from the
legacy secret. The value survives migration reruns and is decoded
to binary when settings are loaded. Back up both keys with the database. Replacing
either key invalidates that version's printed permits; overlapping-key rotation
and key IDs are not implemented.

Recorded legacy permits use version 1 and their original fingerprints. Version 2
rows have distinct fingerprints even when all other claims match an older row.
Reprints use the stored version and do not change issuance data: legacy records
keep their original unversioned QR format, while version 2 records retain `v=2`.
The same exact-ID blacklist applies to both versions.

Deploy the version-aware verifier at the QR origin before issuing version 2
permits. Rolling application files back to a pre-versioning release would make
version 2 codes unverifiable. Roll back only to a compatible release once such
codes exist. The baseline's `down` drops the entire application schema and its
keys; it is not a key-rotation or permit-revocation mechanism.

## Reset local test data

With local services running and migrations applied:

```sh
make reset-db CONFIRM=1
```

This **deletes** permits, check history, admin audit entries, sessions, login counters, current settings,
and blacklist changes. It restores the current initial settings and private
blacklist seed. It restores configured initial data, not a snapshot of earlier
manual database edits. You will be signed out.

The command requires `APP_ENV=local` and explicit confirmation, and uses a one-off
container with the current Compose environment. Seed validation occurs before
changes; data deletion and reseeding are transactional. The resetter consumes
validated initial data directly rather than invoking a versioned migration. Schema
and migration history stay unchanged. Apply pending migrations first. There is no
web reset action, and this command is not a production maintenance procedure.

Local reset restores the HMAC key from the private initial settings file. Retain
that file to reuse the same initial keys; changing them invalidates previously
printed codes of the affected version. Deleting issuance rows alone does not revoke
printed permits of either version.

## Maintenance indexes

The initial migration creates these indexes:

| Index | Query served |
| --- | --- |
| `permit_checks_checked_at (checked_at)` | Oldest history batches and bounded retention preview |
| `admin_sessions_expires_at (expires_at)` | Expired session cleanup during session creation |
| `admin_login_attempts_window (window_started)` | Expired login-attempt cleanup when recording a failure |
| `permits_created_fingerprint (created_at DESC, fingerprint ASC)` | Stable newest-first permit pagination |

MySQL's history secondary index includes the primary key (`id`), covering the
oldest-first `(checked_at, id)` scan without storing a redundant explicit `id`.
The permit index has mixed directions to match the existing ordering, including
ties. Existing exact-ID hashes, primary keys, and `(result, id)` history filtering
remain unchanged. Substring search with a leading wildcard is not made indexable
by these additions; no speculative search indexes were added.

The isolated MySQL suite compares `EXPLAIN` with and without these indexes on
5,000 synthetic rows per table, after analyzing statistics. It temporarily removes
and recreates indexes only in its disposable test database. It checks chosen
indexes and the absence of filesorts for the covered queries. Inspect
`.phpunit.cache/mysql/index-review.json` for estimated access plans, rather than
interpreting this fixture as a production throughput benchmark. Small tables or
different data distributions can legitimately lead to different optimizer choices.

See [operations](operations.md) for retention and recovery. Pruning removes only
old `permit_checks` rows, not permits, revocations, settings, sessions, or keys.

## Admin audit storage

`admin_audit` contains an auto-increment ID, trusted actor username, an
`AdminAuditAction` value, a 64-character target hash, and a UTC timestamp. Its
`(target_hash, id)` index supports private record-history lookup. The target is
an issuance fingerprint, an exact permit-ID hash for blacklist changes, or the
admin username hash for successful login/logout. `Permit::fingerprint()` owns
the unchanged identity algorithm previously calculated in the SQL adapter;
legacy and version 2 fingerprints retain their exact values.

Issuance, blacklist changes, and successful authentication update their data and
append an audit entry in one transaction on the same injected DBAL connection.
Duplicate issuance and no-op blacklist removal do not append another change.
Audit persistence is required; its failure rolls the associated change back.
There is no audit backfill, web editor, or automatic deletion. Reversing the
initial migration drops all application tables and history; prefer compatible
application-code rollback over schema reversal and back up before destructive schema operations. See
[retention and inspection](operations.md#admin-audit-trail) and
[failure semantics](operations.md#failure-and-retry-behavior).
