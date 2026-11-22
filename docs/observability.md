# Request correlation and optional Sentry reporting

[Project overview](../README.md)

## Finding a request in private logs

PHP responses include `X-Request-ID`: a server-generated identifier containing
32 lowercase hexadecimal characters (128 random bits). Copy it from the browser's
Network panel or an HTTP response header, then search the private JSON logs for
the matching `context.request_id` value.

The identifier contains no permit claims, hostname, account information, or time.
Incoming `X-Request-ID` values and existing request attributes/response headers
cannot choose the application's ID. Each handled request gets a fresh value;
nested requests restore their parent scope, and the scope is cleared on return
or failure. Logs emitted outside an HTTP request receive no automatic request ID.

The PSR-3 decorator adds the ID to existing operational logs, including failures,
authentication, generation/reprinting, blacklist changes, and history-write
errors. It does not add a success log or database write for every request. Permit
check history and the transactional admin audit trail keep their existing schema,
contents, retention, and failure semantics.

The entry point generates an ID before loading the framework so startup failures
can be matched too. If the logger throws, a minimal PHP error-log fallback contains
the ID and exception class, preserving the original logging-failure behavior.
The generic error pages and public/printed UI do not display the ID. Apache-served
static files and Apache-generated errors are outside this PHP boundary.

## Enabling Sentry

Sentry is optional. An empty or absent `SENTRY_DSN` leaves the local logger in use
without adding Sentry handlers or initializing a client. The SDK is installed as
a locked runtime dependency; its PHP cURL requirement must be available on the
host even when reporting is disabled. Platform checks include this requirement.

Set these values privately for the deployment:

| Variable | Purpose |
| --- | --- |
| `SENTRY_DSN` | Your project's DSN; empty disables reporting |
| `SENTRY_ENVIRONMENT` | Environment label; defaults to `APP_ENV`, then `production` |
| `SENTRY_RELEASE` | Optional release identifier, such as `permit-manager@<commit>` |

For local Docker use, set the values in the private `.env` and run `make up` to
refresh the PHP container's environment. The tracked example leaves the DSN empty.
For cPanel, provide the environment to both web and CLI PHP using the provider's
supported configuration, or maintain a server-only `config/sentry.php` containing
the three values. Production PHP does not load `.env` automatically. Keep the
actual DSN and deployment-specific copies out of published source and screenshots.

The existing PSR-3 logger remains wired through DI. `config/logger.php` builds
the local Monolog logger and optionally attaches the official Sentry handlers:

- Error-or-higher records containing a `Throwable` become Sentry Issues.
- Operational records at Info and above become Sentry Logs. The SDK's Logs
  handler skips exception records, so those records use the Issues path.
- Errors carry a `request_id` tag and safe extra fields. Structured logs carry
  the same ID as an attribute, allowing a response, local record, and Sentry
  entry to be matched.

Sentry handler failures are isolated from the primary logger. A malformed DSN
leaves local logging available and emits only a generic configuration diagnostic
to PHP's private error log. When `SENTRY_DSN` is set, `app:doctor` checks its syntax
and handler presence alongside local target permissions; it does not contact
Sentry. With a server-only PHP configuration instead, review its startup diagnostic
and verify handler/delivery setup explicitly.
The HTTP and CLI entry points flush buffered telemetry before finishing. Delivery
is best-effort; there is no queue or background retry worker. Network attempts use
a one-second connection timeout and two-second request timeout, so reporting can
add latency, especially during an outage. These are per-attempt limits.

## Remote payload policy

Sentry receives an explicitly reduced representation. Local exception diagnostics
retain their existing detail and remain private.

Error events retain the event ID/time/severity, configured environment/release,
exception classes, and stack locations with project-relative filenames, function
names, and line numbers. Exception messages are replaced; stack arguments/local
variables, source snippets, absolute/external paths, mechanisms, request data,
users, arbitrary contexts/tags/extras, breadcrumbs, and attachments are omitted.
Anonymous names containing paths are replaced.

Structured logs retain only recognized fixed application messages; unknown/dynamic
messages are replaced with a generic description. Allowed context fields are
`request_id`, exception class (`error_type`), HTTP status/method, and guest flag,
each validated for its expected form. Raw IDs, ID hashes, plates, apartments,
signatures, URLs/paths, queries, bodies, cookies, authorization, credentials, and
automatic user/server attributes are excluded from these payloads.

Default SDK integrations are disabled: the application reports through its logger
instead of adding automatic request collectors or a second global error handler.
Keep `zend.exception_ignore_args=On`; stack arguments are unnecessary for grouping
errors and may contain passwords, signing keys, or permit claims. The remote
scrubber also removes variables/source details independently of that PHP setting.

Tracing and profiling are disabled in this logging integration. Excimer is needed
for profiling, **not** error reporting or logs, and is not a hosting requirement
for this application. Enabling profiling later requires a separate reviewed
performance instrumentation and data policy. The SDK's deprecated `enable_logs`
switch is not used; its current Monolog Logs handler enables the supported path.

See the official [PHP Logs](https://docs.sentry.io/platforms/php/logs/),
[Monolog integration](https://docs.sentry.io/platforms/php/integrations/monolog/),
and [configuration options](https://docs.sentry.io/platforms/php/configuration/options/)
for SDK behavior and deployment options.

## Verification

Unit/functional tests verify header/log/event correlation, forged IDs, request
reuse and escaping failures, startup handling, sensitive-payload removal, disabled
or malformed DSNs, and remote transport failures. Enabled-Sentry tests use an
in-memory transport and inspect serialized envelopes. Both PHPUnit suites force
the environment DSN empty; disposable release/browser environments do not inherit
a private development DSN. Automated checks do not send test events to a live project.

After explicitly enabling reporting on a deployment, confirm its environment and
release labels, then verify an approved synthetic operational log/error in Sentry
and match its request ID to the HTTP response/local record. Do not add a publicly
accessible error-testing route. Configure project access and retention in Sentry
for that deployment's operational requirements.
