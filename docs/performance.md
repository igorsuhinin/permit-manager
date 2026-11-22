# Production configuration and measurements

[Project overview](../README.md)

## Twig compilation cache

`config/templates.php` enables compilation caching by default outside
`APP_ENV=local` and `APP_ENV=test`. Debugging stays disabled, variables stay strict,
and HTML escaping stays enabled. Local/test environments disable caching and
enable automatic template reloading. These settings cache compiled PHP templates;
they do not cache responses, permit data, QR results, sessions, or database queries.
Dynamic responses retain `Cache-Control: no-store` and the existing CSP.

| Variable | Behavior |
| --- | --- |
| `TWIG_CACHE_ENABLED` | Empty uses the environment default; `0` disables and `1` enables caching. Other values fail configuration validation. |
| `TWIG_CACHE_DIRECTORY` | Private base directory, absolute or relative to the application root; defaults to `protected/cache/twig`. |
| `TWIG_CACHE_VERSION` | Optional deployment/template revision. Change it for an in-place deployment or private template update. |

A namespace derived from the physical application directory and version is
appended to the base directory. Releases in different physical directories use
different namespaces even when they share a writable cache base. A rollback can
reuse its original compiled templates. Fixed `public_html` installations derive
the namespace from the private application selected by `APP_ROOT`.

Production disables automatic template reloading. When modifying shared/private
Twig files, including optional CSS fragments, use a new physical release directory
or change `TWIG_CACHE_VERSION`. Adding or removing an override also needs this
invalidation: a cached template may contain an earlier include/override decision.
Retain the corresponding private template revision for each rollback release.
Changes to PHP configuration/translation arrays additionally require the provider's
PHP/OPcache refresh procedure where applicable.

Keep the base outside the public document root and give the PHP user access.
For a read-only release, choose a writable private shared base. Create it with
owner-only permissions where the hosting account permits. CLI and web PHP need
the same configuration and access rights; running warmup as another user can
create files the web handler cannot replace. Do not use world-writable permissions
as a workaround. Keep compiled files out of Git, backups of operational data,
published archives, and public file managers.

Before directing traffic to the selected release, run from its private directory:

```sh
php bin/check-platform.php
php bin/console.php app:doctor
php bin/warm-cache.php
```

The warmup command uses the normal DI-bound Twig extensions and effective template
overrides. It compiles all discovered `.twig` names without rendering pages,
connecting to the database, issuing permits, or creating sessions. It creates cache
files with restrictive defaults, prints a template count, and exits nonzero with a
generic diagnostic on failure. Disabled caching produces a no-op message.
`make warm-cache` invokes it through Docker as Apache's `www-data` user. When
opting into caching locally, choose a private base writable by that user.

Warm on the deployment host with the final application path and private overrides.
The build does not ship precompiled Twig files from a different machine/path.
Run HTTP smoke checks after warming, refresh web workers as described below, and
compare both public verification and printed permits. A missing/unwritable cache
produces a generic HTTP 500 and private correlated diagnostic; it does not silently
switch production to uncached operation. Retire old cache namespaces only after
their active/rollback releases and template revisions are no longer needed.

## OPcache and shared hosting

OPcache is useful for this application; JIT is not a requirement. Inspect the
**web handler**, not only CLI PHP, through cPanel's private PHP tools or the hosting
provider. Record the PHP/SAPI version, OPcache availability, timestamp validation,
revalidation interval, file-update protection, available cache memory, and evidence
that scripts are cached and cache hits increase. The application's doctor remains
a read-only CLI configuration check and does not certify web OPcache behavior.

Use the provider's supported worker restart/cache refresh after deploying or
rolling back PHP files, configuration, or translations. A CLI `opcache_reset()`
does not reset a separate web/FPM cache. Do not publish `phpinfo()` or a reset route.
Timestamp validation is useful on shared hosting; do not disable it without a
reliable provider-supported refresh procedure. Deterministic archive timestamps
make a worker refresh particularly important for in-place replacement.

Release archives use the fixed nonzero timestamp `2000-01-01T00:00:00Z`. Timestamp
zero prevented PHP from caching packaged scripts under the measured default
OPcache configuration. A nonzero constant retains reproducible packaging while
allowing opcode caching. It is not a substitute for release-specific directories
or refreshing workers during a deployment.

See the primary [PHP OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php),
[PHP opcode-cache implementation](https://github.com/php/php-src/blob/PHP-8.5/ext/opcache/ZendAccelerator.c),
and [Twig environment options](https://twig.symfony.com/doc/3.x/api.html).

## Runtime dependency audit

Production installs the locked Composer `require` graph with `--no-dev`. The
archive currently contains 45 runtime packages and no PHP development tools,
PCOV, Node/npm tooling, tests, caches, or benchmark endpoints. The shared-hosting
runtime needs Apache, PHP 8.5, MySQL, and the extensions checked by Composer's
generated platform check; no Composer executable is needed after packaging.

| Requirement | Reason |
| --- | --- |
| PDO and `pdo_mysql` | Application database and migrations |
| mbstring | Explicit application contract; also required by the Sentry SDK |
| SimpleXML | Retained for Slim's registered XML body parsers; coverage tooling also uses it in development |
| cURL | Runtime Sentry SDK requirement, including when reporting is disabled |
| iconv | QR encoder dependency and the mbstring polyfill |
| JSON | Runtime dependency graph; built into supported PHP |

SimpleXML is not required by Slim's Composer runtime graph, but its registered
XML parsers call it. Moving the extension to `require-dev` without changing that
behavior would leave a runtime failure path. This audit retains the existing
parsers and requirement. PHPUnit/reporting and analysis packages remain in
`require-dev`; no runtime dependency was upgraded or added for caching.
The production runtime image is checked without development dependencies, and
release verification also rejects a deliberately missing required extension.

## Reproducing HTTP timings

Build the archive, then benchmark only synthetic, disposable data:

```sh
make release
make benchmark ARGS=current
make benchmark ARGS=without-twig TWIG_CACHE=0
```

Reports are written to `.phpunit.cache/benchmark/<label>.json`. Labels accept
lowercase letters, digits, and hyphens. The command uses the extracted production
archive, Apache and MySQL containers, no host ports, and no production/development
database or private configuration. Sentry is disabled. HTTP measurement runs in
Docker; the benchmark does not start Node/npm or a browser.

Eight scenarios cover a valid legacy public check, the anonymous login page,
authenticated permit listing and generation form, unique resident/guest issuance,
and legacy/HMAC reprinting. Real HTTP requests include normal bootstrap, database,
authentication, CSRF, audit/history writes, QR generation, and template rendering.
No assets, browser rendering, network TLS, login password verification, or remote
telemetry are timed. Issuance uses unique IDs; reprinting uses existing records.

For each scenario the harness clears only its isolated Twig namespace and resets
only its disposable **web** OPcache. It records the first request, performs three
warmup requests, waits three seconds for the runtime's two-second file-update
protection, then records 20 sequential requests. Median and nearest-rank p95 are
reported with raw samples, archive hash, PHP/SAPI/architecture, MySQL version, and
OPcache settings/counters. The database and OS filesystem caches are already warm:
the first measurement is a PHP/template cold request, not a cold machine/database.
Concurrency is one; this is a repeatable comparison, not a load or capacity test.

The temporary runtime/reset probe exists only in the isolated extracted benchmark
directory and is removed with that environment. It is never included in release
archives. Run benchmarks without competing tests/builds and repeat on the target
hosting environment through an approved private process before setting budgets.
Local timings do not establish shared-hosting latency or capacity guarantees.

## Recorded local comparison

Measured on 2026-11-11 with Linux/x86_64, PHP 8.5.12 (`apache2handler`),
MySQL 8.4.13, and the Docker runtime image based on PHP 8.5 Apache. OPcache used
`validate_timestamps=1`, `revalidate_freq=2`, `file_update_protection=2`, 128 MiB
of cache memory, and disabled JIT. Each cell is milliseconds.

| Scenario | Baseline first | Baseline warm median | Optimized first | Optimized warm median | Optimized warm p95 |
| --- | ---: | ---: | ---: | ---: | ---: |
| Valid public check | 35.31 | 27.21 | 64.76 | 2.27 | 4.48 |
| Anonymous login page | 28.97 | 27.56 | 54.58 | 2.03 | 3.31 |
| Admin permit list | 35.75 | 34.61 | 64.41 | 2.43 | 4.47 |
| Generation form | 32.10 | 31.29 | 57.51 | 1.90 | 2.52 |
| Issue resident permit | 47.54 | 45.84 | 77.94 | 16.32 | 17.75 |
| Issue guest permit | 47.05 | 44.94 | 80.86 | 15.93 | 18.45 |
| Reprint legacy permit | 43.05 | 41.60 | 72.58 | 13.25 | 14.77 |
| Reprint HMAC permit | 45.11 | 44.70 | 77.13 | 16.78 | 18.73 |

The baseline used the previous zero-timestamp packaging with Twig disabled.
The optimized archive combines the nonzero timestamp correction and production
Twig caching. This comparison does not isolate the contribution of either change.
OPcache remained configured as enabled in both runs; the baseline cached only the
test probe, while the optimized final sample reported 442 cached scripts, 8,537
hits, and a cached application entry point. First requests became slower because
they perform opcode optimization and template compilation. Warmup amortizes that
cost; CLI Twig warmup alone does not populate the web opcode cache.

Measured archive SHA-256 values:

- Baseline: `90113efc25ab18dc518b727e749258ddae32624f3141fda6f9894a940c8c8c0b`
- Optimized: `a6da3881c8955bbda6cd5013a39aea7c11f23bc51d81e7ba06ae1bd399f4cc40`

These identify the measured build candidates; later documentation-only archive
refreshes have different hashes. Raw timings and runtime evidence remain in the
local ignored benchmark reports. This is one sequential comparison without
statistical confidence intervals, traffic concurrency, or a shared-hosting run.
It supports enabling normal Twig/OPcache caching; it does not justify adding
container compilation, a route cache, JIT, or infrastructure services.
The provider-specific OPcache review remains an operator deployment check.
