# Permit Manager

[![Quality workflow status on master](https://github.com/igorsuhinin/permit-manager/actions/workflows/quality.yaml/badge.svg?branch=master&event=push)](https://github.com/igorsuhinin/permit-manager/actions/workflows/quality.yaml?query=branch%3Amaster+event%3Apush)
[![Required line coverage: 95% minimum](https://img.shields.io/badge/line_coverage-95%25_minimum-blue)](docs/development.md#continuous-integration)
[![Required mutation score: 95% minimum](https://img.shields.io/badge/mutation_score-95%25_minimum-blue)](docs/development.md#continuous-integration)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4)](docs/deployment.md#hosting-requirements)
[![Project code license: 0BSD](https://img.shields.io/badge/code_license-0BSD-blue)](#license)

A PHP application for issuing and checking private parking permits. Administrators
can generate printable resident and guest permits, manage revoked permit IDs, and
review issuance records and verification requests. Drivers check permits through
public QR links without signing in.

The application uses Slim 4, PHP-DI, Twig, and MySQL through Doctrine DBAL.
QR images are generated locally and embedded in printable permits.
Only `public/` is web-accessible; application code and private configuration stay
outside the document root. Shared hosting can use either a configurable document
root.

Previously printed permits remain supported without importing issuance records,
provided the corresponding signing keys are retained. New permits use versioned 
HMAC-SHA-256 signatures with a separate key. Legacy signatures remain supported, 
and recorded permits retain their signing version when reprinted. Successful admin 
changes and authentication have a transactional audit trail; issuance retries 
preserve the original record.

## Local development

Requires Docker with Compose v2 and GNU Make. The supplied environment runs
Apache, PHP 8.5, and MySQL 8.4.

```sh
cp .env.example .env
make build
```

The setup command creates private initial settings, defaults the username to
`admin`, and generates credentials and signing keys. Follow the
[initial configuration guide](docs/getting-started.md#configure-initial-values)
to customize these values or retain the keys needed for existing permits.

```sh
make up
make composer ARGS='install'
make setup
make migrate
```

Open [the admin panel](http://localhost:8080/admin) and sign in as `admin` with the
password matching your configured hash. The [health endpoint](http://localhost:8080/health)
returns application liveness. Source files are mounted into the container.

```sh
make help     # Available development commands
make check    # Tests with >=95% line coverage, analysis, templates, style, and dependency checks
make test-mysql # MySQL integration tests in a disposable database container
make release    # Build a production archive with locked runtime dependencies
make release-smoke # Test hosting layouts, backup restoration, and rollback
make prune-checks  # Preview old check-history cleanup; add ARGS='--apply' to delete
make doctor        # Read-only deployment diagnostics using the local CLI configuration
make test-browser # Browser workflows, independent QR decoding, and print checks in Docker
make demo         # Disposable synthetic demo on localhost:18080 (Ctrl+C removes it)
```

GitHub Actions runs quality, MySQL, and mutation checks on pushes, pull requests,
merge-queue checks, weekly, and on manual runs. The release job requires quality
and mutation success and verifies browser workflows and print output before publishing
a production archive and checksum for upload
to shared hosting; see [deployment](docs/deployment.md).
Pushing a version tag such as `v1.0.0` publishes the verified archive and checksum
on the repository's Releases page. Ordinary builds remain downloadable from the
workflow run's Artifacts section; see [downloads and versioned releases](docs/deployment.md#downloading-builds-and-publishing-versions).
See the [development guide](docs/development.md#continuous-integration) for reports
and local equivalents. Dependabot proposes grouped dependency updates; see
[maintenance and required-check setup](docs/dependencies.md).

## Documentation

| Guide                                                 | Contents                                                             |
|-------------------------------------------------------|----------------------------------------------------------------------|
| [Getting started](docs/getting-started.md)            | Credentials, Docker, local database access, common commands          |
| [Configuration and appearance](docs/configuration.md) | Runtime settings, private wording, translations, permit design       |
| [Database and compatibility](docs/database.md)        | Schema, initial data, migrations, legacy QR codes, local reset       |
| [Administration](docs/administration.md)              | Generation, permit records, blacklist, check history, authentication |
| [Development](docs/development.md)                    | Code structure, routes, quality checks, test strategy                |
| [Dependency maintenance](docs/dependencies.md) | Update proposals, Playwright alignment, CI acceptance, owner settings |
| [Admin accessibility](docs/accessibility.md) | WCAG target, automated scans, keyboard controls, manual release checks |
| [Publication review](docs/publication.md) | Source snapshots, private security reports, provenance, and remaining publication checks |
| [Production configuration](docs/performance.md) | Twig caching, OPcache, runtime requirements, reproducible HTTP timings |
| [Deployment and operations](docs/deployment.md)       | Apache/cPanel setup, releases, logging, backups, publication         |
| [Maintenance and recovery](docs/operations.md) | Bounded history cleanup, cPanel cron, backups, and restore drills |

The [architecture decisions](docs/architecture-decisions.md) explain compatibility,
CQRS, validation, and the shared-hosting trade-offs. Browser tooling runs only
inside Docker and is excluded from the production artifact.

PHP responses and private operational logs share an opaque request ID. Optional
[Sentry reporting](docs/observability.md) adds correlated Issues and structured Logs
with a reduced payload; the DSN stays in private deployment configuration.

## License

Project-authored code is available under the [BSD Zero Clause License (0BSD)](LICENSE).
It permits use, modification, and redistribution, including commercial use, without
an attribution requirement. The software is provided as is, with warranty and
liability disclaimers. Third-party dependencies and bundled assets retain their
own licenses; this grant does not replace their terms. Redistribution rights for
the legacy plate font and status icons remain unverified; the complete bundle is
not yet cleared for unrestricted redistribution. The
[publication review](docs/publication.md) records the evidence and remaining checks.
