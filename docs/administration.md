# Administration

[Project overview](../README.md)

Open `/admin` and sign in with the configured admin username and password. A new
database uses the username and password hash from the private initial settings
created by `make setup`; the default username is `admin`.
The login page uses neutral wording. Navigation and the language selector are
shared across admin pages; logout is available in the top toolbar.

## Pages

| Page                      | Features                                                                 |
|---------------------------|--------------------------------------------------------------------------|
| `/admin/permits/generate` | Generate resident or guest permits and print them                        |
| `/admin/permits`          | Search recorded permits, view validity status, and reprint valid permits |
| `/admin/blacklist`        | Search revoked IDs, add an exact ID and optional reason, remove an entry |
| `/admin/checks`           | View check details, filter history, and blacklist or unblock IDs          |

Lists show 25 rows per page. Issuance and check times display in the configured
timezone. Permit records and check history cannot be edited. There is no settings editor,
account manager, permit deletion action, or separate revocation mechanism.

Generation uses the admin session and does not ask for another password. Invalid
input returns to the form with an error. The resulting permit keeps its print
layout, Latvian wording, and public QR target; admin controls are hidden in print.
A generic server error can occur after issuance has already committed if QR,
print rendering, or operational logging fails. Retry the same permit data after
the issue is resolved, or reprint its saved row: this preserves the original
issuance record. See [failure and retry behavior](operations.md#failure-and-retry-behavior).
New IDs must contain non-whitespace content, fit within 255 bytes, and contain no
ASCII control characters. The blacklist uses the same rules. IDs are kept exactly
as entered; see [ID compatibility](database.md#existing-permits).
Older permits that were never recorded are absent from the list but remain
verifiable. The list's Valid/Expired/Revoked status reflects recorded expiry and
the current blacklist; public verification additionally checks the QR signature.

Use **Reprint** on a valid permit's row to open another printable copy with its
stored details, expiry, and QR URL generated from current settings. The browser's
standard print dialog opens automatically once the page, QR image, and fonts are
ready. It opens once per reprint page load; cancelling leaves the permit visible.
You can still use the browser's Print command, including without JavaScript.
No form needs to be filled in, no issuance record is added, and the original issue time is
preserved. The server rechecks expiry and the blacklist on every reprint request;
expired or revoked permits cannot be reprinted. Reprinting does not revoke a lost
copy or extend its validity. The action is translated into all three admin languages.

To revoke a permit, add its **complete ID**, such as `DEMO-001`, to the blacklist.
This immediately affects both recorded and legacy permits sharing that exact ID.
Entering an apartment number does not revoke every permit for that apartment.
Adding a duplicate keeps its existing reason. Removing an entry restores normal
expiration and signature checks; it does not guarantee the permit is valid.

Removing an entry opens a confirmation dialog showing the exact ID and explaining
that its permits may become valid again. The dialog matches the admin styling;
**Cancel** receives initial focus, Escape cancels, and cancellation returns focus
to the original button without submitting a change. JavaScript must be enabled
for removal; otherwise removal buttons remain disabled and an explanation appears.
The server still requires authentication and a valid CSRF token for changes.

Successful blacklist changes show a translated, focused success message after the
redirect. A short-lived, session-bound signed cookie carries only the message code
and expiry; it is cleared on the next rendered admin response. Refreshing the list
does not repeat the message. Invalid or duplicate submissions keep their error
feedback and do not create a success message. Generation and reprinting show their
own success messages on the printable page; those messages are hidden in print.
All confirmations and feedback support English, Latvian, and Russian.

## Permit check history

Requests reaching the public verification routes (`/p/check`, `/p/check.php`)
attempt to append a history row, including GET and HEAD checks of legacy permits.
Opening the admin history page does not create a check. Requests rejected before
route middleware runs, such as unsupported HTTP methods, are not captured there.
There is no backfill of earlier traffic.

The admin table displays the check time, permit ID, apartment, valid-until date,
result, reason, and blacklist actions. Search by permit ID or filter by result.
Request paths, HTTP methods, and status codes are not displayed.

Use **Blacklist ID** or **Remove from blacklist** to change the current blacklist.
Both actions require the confirmation dialog and return to the same filters and
page with success feedback. Blacklisting affects every permit using that exact
ID; removing it allows normal expiry/signature checks again. The recorded result
remains the outcome at the time of the check. Missing, invalid, or shortened IDs
cannot be blacklisted from history. JavaScript is required for confirmation.

Expiry and apartment details are snapshots of the submitted QR fields, including
legacy permits without issuance records. They do not prove a rejected permit is
authentic. Resident QR codes may omit the apartment. Details unavailable in the
request or recorded before this feature show **—**; older rows are not backfilled
by ID because one ID may refer to different issued permits.

Each row stores:

- Requested permit ID, or null when missing or not a string.
- An `id_truncated` flag when the sanitized ID exceeds 255 UTF-8 bytes.
- HTTP method, path, status, and UTC timestamp.
- Result: `valid`, `rejected`, `malformed`, or `error`.
- Stable reason code, where applicable, translated for display.
- The submitted calendar expiry date, when valid and within the SQL date range,
  and apartment ID, when supplied as a nonblank string of at most 255 UTF-8 bytes.
  Oversized apartment values are omitted rather than shortened to a different ID.

Invalid UTF-8 in an ID is replaced safely before storage. Valid, rejected, and
malformed permit pages normally return HTTP 200; inspect the result field to
distinguish them. Unexpected exceptions normally produce HTTP 500.

History excludes signatures, full query strings, plates, IP addresses,
user agents, cookies, exception messages, and stack traces. IDs remain operational
data: restrict access and define a retention policy appropriate to your deployment.
History cleanup is available through the [maintenance CLI](operations.md#check-history-retention).
It runs only when invoked manually or scheduled; there is no admin deletion action.
The local database reset clears history.

Storage is best-effort. An insert failure is logged without copying the requested
ID into routine logs and does not invalidate an otherwise valid permit. A database
outage cannot be recorded in that same database. See [logging](deployment.md#logging).

## Authentication and language

Authenticated sessions expire one hour after login, without sliding renewal.
Anonymous login sessions expire after ten minutes. Session cookies contain opaque
random tokens; only their hashes are stored in the database. Login rotates the
token and logout deletes the server-side session. Changing the admin username or
password hash invalidates authenticated sessions.

Cookies are scoped to `/admin`, HttpOnly, SameSite=Strict, and Secure when the
request scheme is HTTPS. Login, logout, permit generation, and blacklist changes
require session-bound CSRF tokens. Admin middleware responses use `no-store`,
`noindex, nofollow`, and the selected `Content-Language`.

Five failed logins for a server-observed client address within a fixed 15-minute
window trigger a cooldown until that window expires. The database stores address
hashes; changing cookies does not bypass the limit. Successful login clears its
counter. Failure counters increment atomically. Concurrent attempts that have
already passed the limit check can finish; the limit is not a strict cap on
in-flight password checks. Subsequent attempts observe the committed count.
Expired sessions and attempt counters are cleaned during session creation
and failure recording, respectively; there is no background cleanup job.

English, Latvian, and Russian are supported. The selector remembers your choice
for one year. See [translation configuration](configuration.md#admin-translations)
for catalogue files and language precedence. Public checks and printed permits
remain Latvian.

## Frontend resources

All styles, scripts, icons, and fonts are served locally. The admin UI uses
Bootstrap 5.3.8; the generator uses jQuery 4.0.0 and jQuery UI 1.14.2. Printed
permits retain the original font families, including the local plate font.
The browser makes no third-party requests, and deployment needs no frontend build
step. See [frontend dependency maintenance](development.md#frontend-dependencies)
for versions, integrity checks, licenses, and updates.

QR images are generated locally using Endroid QR Code (with BaconQrCode) and
embedded as SVG data URIs. Generation needs no network request, image storage,
GD, or Imagick. The 35 mm printed size remains unchanged. Newly issued QR URLs
include `v=2` and an HMAC signature; reprints retain the stored signature version,
including the original unversioned format for legacy records. The black-and-white
module pattern can differ from earlier encoders. Codes include a four-module quiet zone and
quartile error correction, without a third-party logo.

## Admin audit trail

Successful issuance, blacklist additions/removals, and successful login/logout
are recorded durably with the server-selected actor, action, affected-record
hash, and UTC timestamp. The change and its audit entry commit together. A failed
audit write prevents that change from completing. Duplicate issuance and no-op
blacklist removal do not create another change entry. Reprinting and browsing
remain read workflows with operational logging rather than audit writes.

The trail currently has no admin page; use private database access for inspection.
It retains entries indefinitely by default, independently of check history and
rotated log files. See [audit operations](operations.md#admin-audit-trail) for
stored fields, inspection, retention, and backup handling.
