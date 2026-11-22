# Deployment and operations

[Project overview](../README.md)

## Hosting requirements

Use PHP 8.5 with the extensions required by the lockfile, MySQL, and Apache with
`mod_rewrite` and `.htaccess` support. The local stack uses MySQL 8.4. Composer 2
is needed to build releases or install from source; an uploaded release already
includes production dependencies. Production needs neither Docker nor PCOV,
PHPUnit, a queue worker, or a Composer executable.
The Sentry SDK requires PHP cURL. Excimer is optional profiling tooling and is
not required for error reporting/logs; profiling is disabled in this integration.

The document root must be **`public/`**, containing the entry point, public
`.htaccess`, and permit assets. Application source, dependencies, configuration,
and private overrides stay outside it. The root `.htaccess` denies access if the
private application directory is accidentally exposed. Apache must permit the
public `.htaccess` directives. Existing URLs have no `/public` prefix.

Confirm PHP support on the actual host before switching traffic. The CLI PHP
executable and web PHP handler may use different versions. Run the release's
`php bin/check-platform.php` with the intended CLI executable, then run HTTP smoke
checks to exercise the web handler too. Composer generates an autoload platform
check for PHP and required extensions; the build additionally runs
`composer check-platform-reqs --no-dev`. These local/CI checks do not establish
which PHP versions a particular provider enables.

The standard Compose environment is for local development. Its final Docker
stage includes Composer and PCOV. The separate `runtime` stage is used to test
the production archive without those development tools.

## Build and verify a release

With the development environment running:

```sh
make release
make release-smoke
```

`make release` stages an explicit set of runtime directories, installs the exact
lockfile's production dependencies with an optimized authoritative autoloader,
and checks the platform. It does not change the development `vendor/` directory.
The archive excludes `.env`, `protected/` overrides, tests, development tooling,
Docker/CI configuration, caches, logs, and temporary plans. The included `bin/`
tools are platform checks, HTTP smoke checks, template-cache warmup, and the production console for
diagnostics and maintenance; the local reset command is excluded.
Keep deployment-specific values in private configuration, not maintained source.

The outputs are `dist/permit-manager.tar.gz` and its `.sha256` checksum file. The
archive extracts directly into the selected release directory. File order,
archive timestamps (a fixed nonzero epoch), and archive ownership are normalized. The builder packages
the current working tree; the CI artifact is associated with its workflow commit.
Record the archive checksum and commit when deploying.

`make release-smoke` extracts the archive into a temporary workspace and starts
an isolated Apache/PHP runtime and MySQL database, with no published ports or
persistent database volume. It tests real migrations, private-file isolation,
legacy routes, original assets, synthetic resident/guest/revoked checks, and
both supported deployment layouts. It switches to a candidate and back to the
previous application files, checks served asset hashes, and confirms issuance
and blacklist data survive. It also verifies safe startup errors, rejection of
missing PHP extensions, and denial of an incorrectly selected application root.
Test containers and temporary files are removed when the command exits.

CI builds and tests this archive after quality/MySQL and mutation jobs succeed,
then uploads both files as `permit-manager-release`. Use only a fully successful
run for the intended commit; see [CI acceptance and owner setup](dependencies.md).
There is no automatic production upload.

## Downloading builds and publishing versions

For a regular build, open the repository's **Actions** tab, select **Quality**,
and open a successful run for the intended commit. Under **Artifacts**, download
**permit-manager-release**. Extract the downloaded artifact to get
`permit-manager.tar.gz` and `permit-manager.tar.gz.sha256`. These workflow artifacts
expire after 14 days; sign in to GitHub with read access to download them.

For a versioned download on the repository's **Releases** page, commit and push
the workflow first, then tag the intended commit and push that tag:

```sh
git tag -a v1.0.0 -m 'Permit Manager 1.0.0'
git push origin v1.0.0
```

Tags starting with `v` run the complete workflow. Only after **Required checks**
succeeds does **Publish GitHub release** download that same run's verified
artifact, verify its checksum, and attach both files to a GitHub Release with
generated release notes. Tags containing a hyphen, such as `v1.1.0-rc.1`, are
published as prereleases and are not marked Latest. Branch pushes, pull requests,
manual runs, and schedules do not publish releases.

Release assets are separate from the expiring workflow artifacts. Under a release's
**Assets**, download `permit-manager.tar.gz` and its checksum for server deployment.
GitHub's automatic **Source code** downloads contain repository source without the
production dependencies. Releases do not upload files to the production server.
Use a new version tag for each release; publication does not overwrite an existing
release or its assets.

## Deployment layouts

### Domain with a configurable document root

Extract a release outside the web root, for example:

```text
/home/account/permit-manager/
  releases/release-a/
    public/
    src/, vendor/, config/, bootstrap/, migrations/, ...
  current -> releases/release-a
```

Point the domain document root at
`/home/account/permit-manager/current/public`. The default entry point locates the
application above its own `public/` directory. Keep private configuration in a
shared location outside the web root, using absolute override paths or a
per-release `protected/` symlink. Keep the same private configuration and signing
secret available to both current and rollback releases.

### Fixed cPanel `public_html`

Some cPanel domains have a fixed document root. Keep the full extracted release
outside `public_html`, copy the release's **public contents only** into
`public_html`, and set `APP_ROOT` in the web PHP environment to the absolute private
application path, such as `/home/account/permit-manager/current`.

```text
/home/account/
  permit-manager/current -> releases/release-a
  public_html/
    index.php
    .htaccess
    permit/
```

`APP_ROOT` selects bootstrap/dependency files; it does not change asset URLs or
private configuration path resolution. Use the provider's environment mechanism
for PHP-FPM if Apache `SetEnv` is not passed through. If environment configuration
is unavailable, adapt only the application-root fallback in the deployed public
entry point, keeping that host-specific change out of Git and repeating it for
releases. Preserve provider-managed PHP handler directives when updating the
public `.htaccess` file. Ask the provider to confirm supported document roots and
PHP handler configuration; these vary by account.

Copy public files from the same release as the selected private application.
During updates, replace app-owned public files and remove obsolete app-owned files;
preserve provider-managed files such as ACME challenges. Plan a maintenance window
when a fixed public directory cannot be switched atomically.

## Fresh installation

1. Select the intended web and CLI PHP versions, download the verified archive,
   and validate its checksum before extracting it into a new private directory:

   ```sh
   sha256sum -c permit-manager.tar.gz.sha256
   mkdir release-a
   tar -xzf permit-manager.tar.gz -C release-a
   cd release-a
   php bin/check-platform.php
   ```

2. Configure one of the layouts above, including `public/.htaccess` and, where
   needed, `APP_ROOT`. Enable HTTPS before real admin use.
3. Create a database/user. Supply `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
   `DB_USERNAME`, and `DB_PASSWORD` to both the web handler and migration CLI.
4. Create private initial settings from the CLI before the first migration:

   ```sh
   php bin/console.php app:setup
   ```

   Answer the username, base URL, and timezone questions, then the hidden
   credential/key questions. Save the generated password securely, or provide your own through the hidden
   prompt. Enter the original signing secret and HMAC key when retaining existing
   printed permits; blank inputs generate independent new keys. The file defaults
   to `protected/initial-settings.php`, with owner-only permissions. Use
   `INITIAL_SETTINGS_CONFIG` for an absolute, persistent private path shared across
   releases, and run setup/migrations as the account that owns it. Existing
   databases continue using their stored settings; setup does not update them.
   Upload the private initial blacklist and
   public wording/template overrides separately; use absolute paths for shared
   private files. Set `INITIAL_BLACKLIST_CONFIG` explicitly to detect missing data.
5. Apply migrations explicitly from the private release directory:

   ```sh
   php vendor/bin/doctrine-migrations migrate --no-interaction
   ```

6. Inspect the initialized release without writes:

   ```sh
   php bin/console.php app:doctor --no-interaction
   ```

   Resolve reported problems privately; see [diagnostic limits](operations.md#read-only-deployment-diagnostics).
   Configure the writable private cache base, then compile the selected templates
   as the intended PHP user:

   ```sh
   php bin/warm-cache.php
   ```

7. Run HTTP smoke checks and the manual release checks below before directing
   visitors to the installation.

PHP outside Docker does not automatically load Compose's `.env`. Configure the
host's environment for both CLI and web PHP. Initial seed variables do not change
runtime settings in an initialized database. The consolidated baseline is for a
fresh application database; it is not an upgrade path from deleted development
migrations. For source installations, install the lockfile with
`composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
before running platform checks and migrations.

## HTTP smoke checks

From a machine with PHP 8.5 and HTTP stream access, run:

```sh
php bin/smoke-test.php https://example.com
```

The command checks liveness, anonymous admin redirects, legacy generator routes,
generic 404 responses, private-file isolation, and public assets. It follows only
the known `/permit` to `/permit/` canonical redirect. When run from the deployed
release, it compares asset contents with the release's files too. Do not follow
an HTTPS redirect implicitly: supply the final HTTPS origin. A failed check
returns a nonzero status.

For database/legacy permit verification, optionally supply a private JSON file:

```json
[
  {
    "query": {
      "id": "DEMO-001",
      "plate": "AB1234",
      "term": "31.12.2099",
      "sign": "REPLACE_WITH_THE_ACTUAL_SIGNATURE"
    },
    "contains": "Ir derīga"
  }
]
```

```sh
php bin/smoke-test.php https://example.com /private/path/smoke-checks.json
```

Use exact claims from known resident and guest QR URLs, with expected text matching
private public-page translations. The placeholder above is not a valid signature.
Each case is checked through both `/p/check` and `/p/check.php`; add rejected cases
when useful. The command prints case numbers rather than IDs or signatures.
Checks are real requests and create normal check-history rows; anonymous admin
requests also create login sessions. They do not issue or change permits.

## Updating and rolling back

1. Keep the previous verified archive/release and back up the database and private
   configuration before changing schema or settings. Record the current release.
2. Extract the candidate into a separate private directory, attach private
   configuration, and run platform checks with the host's selected PHP binary.
   Select a writable private Twig base and warm its release-specific namespace
   using `php bin/warm-cache.php` with the same environment/access as web PHP.
3. Review migration compatibility before applying pending migrations. MySQL DDL
   commits implicitly. Switching application files back does not reverse schema
   changes, runtime settings, or signing keys.
4. Switch the document root/current symlink to the candidate, or replace the
   matching public files and private application pointer during maintenance.
   Refresh PHP workers/OPcache through the hosting provider's supported mechanism.
5. Run smoke checks with known permit claims and verify appearance and admin use.
   Keep the previous compatible release until the candidate is accepted.
6. If checks fail, restore the previous document root/current pointer and, for
   fixed `public_html`, restore that release's public files too. Refresh PHP caches
   and rerun the same smoke checks. Preserve the live database for compatible
   application rollback.

The current initial migration targets a fresh application database; the previous
development migrations have been consolidated. It initializes the legacy secret
and independent HMAC key from the documented configuration. Preserve the original
legacy secret when replacing the old service so existing printed permits still
verify. After version 2 codes have been printed, application rollback must
retain support for both versions; a pre-versioning release cannot check those
new codes. Keep both signing keys in database backups. See
[signature compatibility](database.md#signature-versions).

Where atomic symlink replacement is supported, prepare a new link and rename it
into place on the same filesystem:

```sh
ln -s releases/release-b next
mv -Tf next current
```

To roll back, repeat with `releases/release-a`. Refresh PHP caches after either
switch; symlink replacement alone does not invalidate OPcache or realpath caches.
For incompatible schema changes, use a reviewed forward fix or a tested database
restore procedure; a restore can discard writes made since the backup. Do not run
migration `down` automatically or use the local reset command for recovery.
The automated rollback test covers application/public file switching with a
compatible schema, not arbitrary historical schema recovery.

## Manual release checks

- Confirm generic 404/500 responses and private-file isolation on the actual host.
- Sign in, generate resident and guest permits, and reprint an existing valid record.
- Check known legacy resident and guest QR URLs against their expected results.
- Check private wording, status icons, plate fonts, and both print layouts.
- Scan embedded QR images and confirm the expected public origin and paths.

Keep the same public hostname and legacy paths for already printed links, or
provide equivalent routing at their original address. Changing database `base_url`
only affects newly generated links.

Behind a reverse proxy, configure the server-observed scheme and client address
correctly. Secure cookies depend on an HTTPS request scheme, and login limits use
`REMOTE_ADDR`. The application does not accept arbitrary forwarded headers as a
trusted proxy configuration.

## PHP settings and template caching

The Docker PHP configuration disables error display and enables logging. Apply
equivalent settings on the host, including startup/fatal errors outside application
handling:

```ini
display_errors = Off
display_startup_errors = Off
log_errors = On
zend.exception_ignore_args = On
expose_php = Off
```

Twig compilation caching defaults to enabled outside local/test environments.
Configure a writable private `TWIG_CACHE_DIRECTORY` for read-only releases, then
run `php bin/warm-cache.php` as the appropriate PHP user before traffic switches.
Physical release directories get separate cache namespaces; set a new
`TWIG_CACHE_VERSION` for in-place/private template updates. Keep web and CLI values
aligned. Refresh web workers/OPcache using the host's supported procedure after
deployments and rollback; CLI reset does not refresh a separate web cache. See
[production configuration and measurements](performance.md) for exact settings,
warmup/failure behavior, runtime requirements, and benchmark methodology.

## Logging

Monolog is injected through PSR-3 `LoggerInterface`.
[config/logger.php](../config/logger.php) selects JSON output and an `info` minimum
level. By default it uses PHP's configured `error_log`; locally:

```sh
make logs SERVICE=php
```

On cPanel, use the host's PHP error log or configure the logger factory's `path`
to `__DIR__ . '/../protected/logs/app.log'`. Create the directory with write access
for the PHP user. A configured file path enables daily rotation with 14-file
retention. That retention applies to application files, not the host's PHP or
Apache logs. There is no application file rotation when the default PHP log
destination is used.

| Level      | Examples                                                          |
|------------|-------------------------------------------------------------------|
| `critical` | Application startup failure                                       |
| `error`    | Unhandled server exception; failure to store permit check history |
| `warning`  | Failed admin authentication or CSRF validation                    |
| `notice`   | HTTP client errors and invalid generation input                   |
| `info`     | Permit generation, admin login/logout, blacklist changes          |

Public errors use generic responses, including startup failures. Operational
exception logs contain details and traces; restrict their access. Request context
omits query strings, bodies, cookies, authorization headers, and raw permit IDs.
Blacklist changes log an ID hash. History-write failures log the exception type.
Exception argument values are excluded when the documented PHP setting is active;
exception messages and server diagnostics still need private handling. A logging
failure has a PHP error-log fallback.

Public verification results go to [database check history](administration.md#permit-check-history),
not routine application request logs. The Docker Apache access log omits query
strings and referrers but **does include client IP addresses**, method, path,
status, and response size. Review the host's access-log format separately; changing
application logging does not change Apache or proxy logs.

PHP responses include `X-Request-ID`, matching the `request_id` in private
operational logs. Optional Sentry Issues/Logs use the same identifier and remove
request/permit data and exception messages from remote payloads. Keep the DSN
private. See [correlation and Sentry configuration](observability.md) for setup,
payload policy, delivery limits, and verification.

## Backups and maintenance

Back up the database and private configuration together. The database contains
permit records, revocations, check history, both signing keys, and credentials;
private files contain initial revocations and deployment-specific appearance.
Protect backups accordingly. Migrations execute explicitly during releases and
MySQL DDL is not generally transactional. Do not use the local reset command as a
production recovery operation.

Use the [maintenance and recovery guide](operations.md) for bounded history
pruning, cPanel cron, private backup storage, SQL/file restoration, and verification
of both signing formats. Configure the retention period and stable shared lock
path before scheduling cleanup. No cleanup or backup job is installed automatically.
`make release-smoke` includes a disposable SQL/private-file restoration drill.
A health response alone does not establish database readiness or backup correctness.

The release job also runs `make test-browser` against an extracted archive using
only synthetic data, including independent decoding from rendered images/PDFs.
Browser and PDF tooling stay in Docker/CI and are excluded from production.
See the [demonstration guide](demo.md) for reports and presentation evidence.

## Sharing source

The maintained source uses neutral wording and synthetic example IDs. Keep `.env`,
private initial settings, blacklist data, public wording, template overrides, logs, and caches out
of shared releases. Custom private paths require their own exclusion rules.
`.gitignore` prevents new files from being tracked; it does not remove private
values from earlier commits.

Use the [publication review](publication.md) for source and history checks,
private secret-scan reports, and the source review archive. That archive is
separate from the runtime deployment archive and contains no Git metadata.
Existing history and source snapshots have different disclosure implications.
The legacy plate font and status icons have unresolved redistribution rights;
the project code's 0BSD license does not clear those assets. Runtime archives
retain the project license and installed dependencies' upstream license files.

## HTTP security policies

All application responses, including permit checks, errors, redirects, and admin
pages, use `Cache-Control: no-store`, `Referrer-Policy: no-referrer`,
`X-Content-Type-Options: nosniff`, and `X-Frame-Options: DENY`. Public verification
must reach the application so that blacklist changes take effect immediately;
disable CDN/proxy/page caching for dynamic routes. Static assets remain cacheable.

The application sends a Content Security Policy with a fresh cryptographic nonce
on every response. Scripts, CSS, fonts, and images load locally; embedded SVG QR
images use `data:` URIs. Frames, objects, inline event handlers, remote resources,
and browser connections are blocked. Trusted private inline styles use the nonce.
`style-src-attr 'unsafe-inline'` is limited to style attributes because jQuery UI
uses them to position its calendar. Inline style elements still require a nonce.
The policy targets current browsers, matching jQuery UI's support policy.

Enable Apache `mod_headers` to apply the same referrer, MIME, and framing headers
to static files and Apache-generated errors via `public/.htaccess`. The Docker
image enables it. Dynamic responses and native startup errors also set their
headers in PHP. Keep a single application CSP: adding a second policy at a proxy
or in Apache can block the application's per-response nonce. Avoid overriding the
application's cache policy. `make release-smoke` and `bin/smoke-test.php` verify
headers, every packaged public asset, dynamic caching, and CSP over real HTTP.

Complete private template overrides need the nonce and local asset changes
explained in [template configuration](configuration.md#templates-and-assets).
CSS-only fragments remain compatible. Deploy the updated `public/` assets and
`.htaccess` together with the PHP and templates, then run the deployment smoke
checks before serving traffic.
