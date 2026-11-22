# Getting started

[Project overview](../README.md)

## Requirements

Install Docker with Compose v2 and GNU Make. PHP and Composer run inside the
container; no host PHP installation or frontend build is required. The development
stack uses Apache with URL rewriting, PHP 8.5, Composer 2, and MySQL 8.4.

Run all commands from the project root.

## Configure initial values

Copy the Docker connection/port configuration, start the environment, and install dependencies:

```sh
cp .env.example .env
make up
make composer ARGS='install'
```

Prepare all initial business settings with one command:

```sh
make setup
```

The command asks for the admin username, base URL, timezone, admin password,
legacy signing secret, and HMAC key, in that order. Press Enter to accept the
displayed defaults: `admin`, `http://localhost:8080`, and `Europe/Riga`.
Blank hidden inputs generate a random password, a legacy signing
secret, and an independent HMAC key. The generated password is displayed once;
save it securely. Only its hash is stored. The file defaults to
`protected/initial-settings.php`, with permissions `0600`, and is excluded from
Git, Docker build contexts, source exports, and runtime archives.

You can enter every value interactively without options. Optional flags prefill
nonsecret values and skip their questions:

```sh
make setup ARGS='--admin-username=operator --base-url=https://example.com --timezone=UTC'
```

The hidden prompts accept a custom password and existing signing keys. Passwords
and legacy secrets retain their exact input, including spaces. **Retain
the original secret for legacy permits and the original HMAC key for version 2
permits.** Blank key inputs are appropriate for a new installation. Secret values
are not command-line options and need not appear in shell history.

For a new installation using all defaults without questions:

```sh
make setup ARGS='--no-interaction'
```

Use `INITIAL_SETTINGS_CONFIG` in `.env` only to select an alternative private file
path. The other initialization values are no longer environment parameters.
Keep connection/port settings, runtime environment settings, and optional private
configuration paths in `.env`. Outside Docker, PHP does not load `.env`; supply
path/connection variables through your hosting configuration.

Setup needs no database connection, refuses to overwrite an existing file, and
does not change an initialized database. Private initial values seed only a fresh
baseline migration and an explicitly requested local data reset. Back up that
file privately along with the database; it contains the original signing keys.
The admin password hash is independent of both signing keys.

To supply an initial blacklist, follow [initial data](database.md#initial-data).
For deployment-specific public wording, follow
[private appearance configuration](configuration.md#public-wording).

## Start the application

Apply migrations after setup:

```sh
make migrate
```

| URL                                            | Purpose                                                   |
|------------------------------------------------|-----------------------------------------------------------|
| `http://localhost:8080/`                       | Configurable landing page                                 |
| `http://localhost:8080/admin`                  | Admin login and navigation                                |
| `http://localhost:8080/admin/permits/generate` | Authenticated permit generator                            |
| `http://localhost:8080/p/check`                | Public verification; requires a permit's query parameters |
| `http://localhost:8080/health`                 | JSON liveness response; does not check database readiness |

Sign in with the configured username (`admin` by default) and the generated or
chosen password. There is no built-in default password. Generate a test permit and open its QR target to check
it. If checking an existing QR URL locally, replace only its origin with the local
address and retain the complete path and query string. The same signing secret,
blacklist, and timezone are needed for comparable results.

## Database access

Default local connection values:

| Setting  | Inside PHP container | From the host or database IDE |
|----------|----------------------|-------------------------------|
| Host     | `mysql`              | `127.0.0.1`                   |
| Port     | `3306`               | `3307`                        |
| Database | `permits`            | `permits`                     |
| User     | `permits`            | `permits`                     |
| Password | `permits_local`      | `permits_local`               |

Both published ports bind to `127.0.0.1`. Override `APP_HTTP_PORT`, `MYSQL_PORT`,
and `MYSQL_*` in `.env` as needed. Changing the HTTP port does not update
the private initial `base_url` or an existing database's `base_url` automatically.

MySQL stores data in the named `mysql_data` volume. `make down` preserves it.
`MYSQL_*` initialization credentials apply when MySQL first initializes that
volume; editing `.env` does not change existing MySQL accounts.

## Common commands

| Command                        | Action                                                        |
|--------------------------------|---------------------------------------------------------------|
| `make help`                    | List all targets                                              |
| `make up`                      | Build and start services; apply changed container environment |
| `make down`                    | Stop and remove containers, retaining database data           |
| `make build`                   | Build the PHP image with updated base images                  |
| `make restart`                 | Restart services with their existing container configuration  |
| `make ps`                      | Show status and published ports                               |
| `make logs SERVICE=php`        | Follow Apache/PHP logs; omit `SERVICE` for all services       |
| `make shell`                   | Open a shell in the PHP container                             |
| `make php ARGS='-v'`           | Run PHP as your host user                                     |
| `make composer ARGS='install'` | Run Composer as your host user                                |
| `make db`                      | Open MySQL as the application user; prompts for its password  |
| `make config`                  | Validate Compose configuration                                |
| `make architecture` | Enforce domain/application/adapter dependency rules |
| `make test-mysql` | Run MySQL integration tests in disposable containers |
| `make lint-ci` | Validate the GitHub Actions workflow |
| `make release` | Build the production archive with locked dependencies |
| `make release-smoke` | Test the release, cPanel layout, and rollback in disposable containers |
| `make setup` | Create private initial settings and show a generated password once |
| `make doctor` | Inspect database/schema/settings, private configuration, and target permissions without writes |

Use `make up` after changing `.env`, Compose, or Docker image configuration.
Apache serves `public/`; static permit assets retain their `/permit/` URLs.
Restarting alone does not replace container environment values. The supplied
Docker configuration is intended for local development; use the
[deployment guide](deployment.md) for hosting.
