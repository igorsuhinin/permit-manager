# Architecture decisions

[Project overview](../README.md)

These decisions describe the implemented service and its shared-hosting limits.
Detailed wire formats and recovery procedures remain in the database and
operations guides.

## 1. A modular monolith for ordinary Apache hosting

The runtime is one PHP application using Slim, PHP-DI, Twig, and Doctrine DBAL
with MySQL. Only `public/` is web-accessible. A configurable document root or a
separate cPanel `public_html` plus `APP_ROOT` keeps private files outside it.

Concrete services contain behavior; interfaces describe persistence and external
capabilities required by the core. Domain/application code does not depend on
HTTP, SQL adapters, or the container. Deptrac enforces those boundaries. PSR HTTP,
logging, and clock contracts are used where they provide a useful boundary.

This fits a single shared-hosting account without a daemon, broker, or separate
read service. Docker, Node/npm, browser automation, and PDF tools are used only
in development/CI. Production still requires the documented PHP version,
extensions, Apache configuration, and database support.

## 2. Explicit legacy compatibility alongside modern signatures

Previously printed permits can lack an issuance record, so verification depends
on their signed claims and the current blacklist, not on a mandatory row in
`permits`. Existing public URLs, Latvian presentation, and original MD5 signing
format remain supported. MD5 is confined to that compatibility path.

New issuance uses version 2: HMAC-SHA-256 with an independent key and a
purpose/version/type-tagged, byte-length-prefixed payload. Unknown versions fail;
there is no fallback from a rejected HMAC to the legacy algorithm. Reprints use
the stored version and identity. Fixed fixtures verify the original fingerprints
and wire behavior.

The trade-off is retaining both keys and both verification paths for outstanding
paper permits. Key replacement invalidates that format's printed codes; overlapping
key rotation is not implemented. Backups must preserve both keys. See
[signature versions](database.md#signature-versions).

## 3. Synchronous CQRS on one database

Immutable command/query messages have concrete, directly injected handlers.
Queries use read capabilities; commands own the writes they need. There is no
generic dispatch bus, separate read database, eventual consistency, or queue.
Shared printing and pagination services avoid handlers calling other handlers.

Admin changes and their audit entries share a transaction on the same connection.
Issuance commits before QR/HTML rendering; its unique claims fingerprint makes
identical retry idempotent. Operational logging and HTTP delivery can fail after
commit, so an error response does not prove a write was rolled back. Audit rows
describe commits rather than successful delivery of a printable page.

This keeps the consistency model understandable and works on shared hosting.
It deliberately leaves retries synchronous and explicit. No events or outbox are
needed for the current local side effects. See
[failure behavior](operations.md#failure-and-retry-behavior).

## 4. Validation belongs at the boundary that owns the rule

The immutable permit contains claims and their stable identity. Concrete
validators own issuance/blacklist rules; HTTP validators own parsing, pagination,
and CSRF concerns. New issuance's byte/control-character restrictions are distinct
from verification of old signed claims. Applying new issuance rules to old codes
would break compatibility.

Controllers translate input/results and call application workflows. Twig receives
the data its templates use, with escaping and strict variables. Typed enums carry
outcomes and audit actions; public wording is a separate Latvian translation
concern. Failures produce generic visitor responses and private diagnostics.

The consequence is a few explicit validators and ports instead of a framework
validation/ORM model hierarchy. Unit, functional, architecture, and browser checks
cover the rules at their respective boundaries.

## 5. Local assets, bounded maintenance, and verifiable releases

QR images are generated locally and embedded as SVG. Reviewed frontend files,
fonts, licenses, and checksums are committed, so the browser needs no CDN or
remote QR service. CSP, per-response nonces, and non-cacheable dynamic responses
protect those pages while retaining the existing layout and private CSS hooks.

History pruning is a bounded CLI operation with preview mode and a nonblocking
file lock. cPanel cron can invoke it; the lock coordinates one host/shared file,
not a distributed cluster. Audit records retain minimal identifying hashes and
are kept indefinitely by default, separately from request-history retention.

CI runs quality gates and isolated MySQL tests, builds a locked production archive,
and tests its hosting layouts, recovery, rollback, and browser workflows. The
browser suite independently reads QR pixels from screen and rasterized PDF output
and compares reviewed print/public-page baselines. These checks support release
review; they do not replace checking the actual host, printer settings, backups,
or scanner devices. The deployment artifact excludes test tools and private data.
