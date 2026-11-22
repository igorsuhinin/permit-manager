.PHONY: help up down build restart logs ps shell php composer db config

.DEFAULT_GOAL := help

CI_TOOLS_IMAGE := $(shell awk '$$1 == "FROM" {print $$2; exit}' docker/tools.Dockerfile)
PLAYWRIGHT_IMAGE := $(shell awk '$$1 == "FROM" {print $$2; exit}' tests/Browser/Dockerfile)
SECURITY_IMAGE := $(shell awk '$$1 == "FROM" {print $$2; exit}' docker/security.Dockerfile)

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "} /^[a-z-]+:.*## / {printf "  %-12s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

up: ## Build and start the environment
	docker compose up -d --build

down: ## Stop containers (keep database data)
	docker compose down

build: ## Build the PHP image with updated base images
	docker compose build --pull

restart: ## Restart running services
	docker compose restart

logs: ## Follow logs (optionally SERVICE=php or SERVICE=mysql)
	docker compose logs -f $(SERVICE)

ps: ## Show service status and ports
	docker compose ps

shell: ## Open a shell in the PHP container
	docker compose exec php bash

php: ## Run PHP (e.g. make php ARGS='-v')
	docker compose exec --user "$$(id -u):$$(id -g)" php php $(ARGS)

composer: ## Run Composer (e.g. make composer ARGS='install')
	docker compose exec --user "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer php composer $(ARGS)

db: ## Open MySQL using the application user (prompts for password)
	docker compose exec mysql sh -c 'exec mysql -u"$$MYSQL_USER" -p "$$MYSQL_DATABASE"'

config: ## Validate Compose configuration
	docker compose config --quiet

.PHONY: test coverage stan psalm cs-check cs-fix phpcs phpcbf audit check rector rector-fix infection lint-templates validate

test: ## Run unit and functional tests (optionally ARGS='--testsuite Unit')
	$(MAKE) composer ARGS='test -- $(ARGS)'

.PHONY: test-mysql

test-mysql: ## Run MySQL integration tests in a disposable isolated environment (optionally ARGS='--filter ...')
	@set -eu; \
	test_project="permit-tests-$$(id -u)-$$$$"; \
	trap 'docker compose --env-file /dev/null -p "$$test_project" -f compose.test.yaml down --volumes' EXIT; \
	docker compose --env-file /dev/null -p "$$test_project" -f compose.test.yaml run --build --rm -T \
		--user "$$(id -u):$$(id -g)" php composer test-mysql -- $(ARGS)

.PHONY: lint-ci

lint-ci: ## Check GitHub Actions workflows with actionlint (requires Docker)
	docker run --rm -v "$(CURDIR)/.github/workflows:/workflows:ro" \
		$(CI_TOOLS_IMAGE) /workflows/quality.yaml

.PHONY: release release-smoke

release: ## Build dist/permit-manager.tar.gz with locked production dependencies
	docker compose exec --user "$$(id -u):$$(id -g)" -T -e COMPOSER_HOME=/tmp/composer php bash bin/build-release.sh

release-smoke: ## Verify the release archive, cPanel layout, and rollback in disposable containers
	bash bin/test-release.sh

.PHONY: source-review secrets-source secrets-history

source-review: assets-check ## Export a source review candidate without history or private runtime state
	@set -eu; review_list=$$(mktemp); trap 'rm -f "$$review_list"' EXIT; \
	git ls-files --cached --others --exclude-standard -z > "$$review_list"; \
	docker compose exec --user "$$(id -u):$$(id -g)" -T php bash bin/build-source-review.sh < "$$review_list"

secrets-source: source-review ## Scan the extracted source candidate locally with redacted Gitleaks reports
	mkdir -p .phpunit.cache/publication
	docker run --rm --platform linux/amd64 --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=64m \
		--user "$$(id -u):$$(id -g)" -v "$(CURDIR)/dist:/artifacts:ro" \
		-v "$(CURDIR)/.phpunit.cache/publication:/reports" --entrypoint sh $(SECURITY_IMAGE) -ec \
		'mkdir /tmp/source; tar -xzf /artifacts/permit-manager-source-review.tar.gz -C /tmp/source; \
		exec gitleaks dir --redact=100 --no-banner --log-level error --ignore-gitleaks-allow \
		--gitleaks-ignore-path /dev/null --report-format json --report-path /reports/source-secrets.json /tmp/source'

secrets-history: ## Scan locally reachable Git history without fetching or uploading it
	mkdir -p .phpunit.cache/publication
	docker run --rm --platform linux/amd64 --network none --read-only --tmpfs /tmp:rw,noexec,nosuid,size=64m \
		--user "$$(id -u):$$(id -g)" -v "$(CURDIR):/repo:ro" \
		-v "$(CURDIR)/.phpunit.cache/publication:/reports" $(SECURITY_IMAGE) \
		git --redact=100 --no-banner --log-level error --ignore-gitleaks-allow --gitleaks-ignore-path /dev/null \
		--log-opts=--all --report-format json --report-path /reports/history-secrets.json /repo

.PHONY: lint-shell

lint-shell: ## Check deployment shell scripts using ShellCheck
	docker run --rm --entrypoint shellcheck -v "$(CURDIR)/bin:/scripts:ro" \
		$(CI_TOOLS_IMAGE) /scripts/build-release.sh /scripts/test-release.sh /scripts/test-recovery.sh \
		/scripts/test-browser.sh /scripts/check-ci-results.sh /scripts/build-source-review.sh

.PHONY: test-browser demo browser-report demo-capture browser-lock browser-format

.PHONY: benchmark

benchmark: ## Measure synthetic production HTTP requests in Docker (optional ARGS=report-label)
	BENCHMARK_TWIG_CACHE_ENABLED="$(TWIG_CACHE)" bash bin/test-browser.sh --benchmark $(ARGS)

.PHONY: warm-cache

warm-cache: ## Compile configured Twig templates into the private release cache
	docker compose exec --user www-data -T php php bin/warm-cache.php

browser-lock: ## Regenerate the browser npm lockfile inside Docker
	docker run --rm --platform linux/amd64 --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/tests/Browser:/work" -w /work $(PLAYWRIGHT_IMAGE) \
		npm install --package-lock-only --ignore-scripts --no-fund

browser-format: ## Format/lint browser test source using the Docker tool image
	docker run --rm --platform linux/amd64 --user "$$(id -u):$$(id -g)" -v "$(CURDIR)/tests/Browser:/work/source" \
		-w /work/source permit-manager-browser-tools /work/node_modules/.bin/biome check --write .

.PHONY: browser-check

browser-check: ## Build and check locked browser tooling in Docker without a database
	docker build --platform linux/amd64 -f tests/Browser/Dockerfile -t permit-manager-browser-tools .
	docker run --rm --platform linux/amd64 --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		permit-manager-browser-tools npm run check

test-browser: ## Test a production archive in disposable browsers/MySQL (requires make release; optional ARGS)
	bash bin/test-browser.sh $(ARGS)

demo: ## Run an isolated synthetic demo at localhost:18080 until Ctrl+C (requires make release)
	bash bin/test-browser.sh --serve

browser-report: ## Serve the last browser report at localhost:9323 (all Node tooling runs in Docker)
	docker run --rm --platform linux/amd64 --init --user "$$(id -u):$$(id -g)" -p 127.0.0.1:9323:9323 \
		-v "$(CURDIR)/.phpunit.cache/browser:/reports:ro" permit-manager-browser-tools \
		node node_modules/@playwright/test/cli.js show-report /reports/html --host 0.0.0.0 --port 9323

demo-capture: test-browser ## Refresh illustrative admin screenshots after a passing browser run
	cp .phpunit.cache/browser/screenshots/admin-permits.png docs/screenshots/admin-permits.png
	cp .phpunit.cache/browser/screenshots/admin-generator.png docs/screenshots/admin-generator.png

coverage: ## Run all tests and enforce at least 95% total line coverage
	$(MAKE) composer ARGS='coverage'

stan: ## Run PHPStan at the strictest level
	$(MAKE) composer ARGS='stan'

psalm: ## Run Psalm at its strictest level (optional ARGS)
	$(MAKE) composer ARGS='psalm -- $(ARGS)'

cs-check: ## Check PHP formatting, modern style rules, and the 120-character limit
	$(MAKE) composer ARGS='cs-check'

cs-fix: ## Apply PHP_CodeSniffer and PHP-CS-Fixer automatic fixes
	$(MAKE) composer ARGS='cs-fix'

phpcs: ## Run PHP_CodeSniffer (PSR-12, strict PHP, and line length)
	$(MAKE) composer ARGS='phpcs'

phpcbf: ## Apply PHP_CodeSniffer automatic fixes (long lines need manual wrapping)
	$(MAKE) composer ARGS='phpcbf'

audit: ## Check Composer dependencies for security advisories
	$(MAKE) composer ARGS='audit'

rector: ## Preview PHP upgrade suggestions without changing files
	$(MAKE) composer ARGS='rector'

rector-fix: ## Apply configured Rector refactorings
	$(MAKE) composer ARGS='rector-fix'

infection: ## Run mutation tests for domain and application code
	$(MAKE) composer ARGS='infection'

lint-templates: ## Check Twig template syntax
	$(MAKE) composer ARGS='lint-templates'

validate: ## Validate Composer configuration and dependency lockfile
	$(MAKE) composer ARGS='validate --strict'

check: validate assets-check coverage stan psalm architecture lint-templates cs-check rector audit ## Run standard checks (mutation tests: make infection)

.PHONY: assets-check

assets-check: ## Verify committed frontend assets and licenses offline
	$(MAKE) composer ARGS='assets-check'

.PHONY: migrate migration migration-status

migrate: ## Apply database migrations
	$(MAKE) composer ARGS='migrations -- migrate --no-interaction'

migration: ## Generate a blank Doctrine migration
	$(MAKE) composer ARGS='migrations -- generate'

migration-status: ## Show database migration status
	$(MAKE) composer ARGS='migrations -- status'

.PHONY: reset-db

reset-db: ## Reset LOCAL data to original config and empty permits (requires CONFIRM=1)
ifeq ($(CONFIRM),1)
	docker compose run --rm -T --no-deps --user "$$(id -u):$$(id -g)" php php bin/reset-db.php --confirm
else
	@echo "This deletes local permits, blacklist changes, settings changes, and admin sessions. Run make reset-db CONFIRM=1 to proceed."
	@exit 1
endif

.PHONY: doctor prune-checks setup

setup: ## Create private initial settings (ARGS='--no-interaction' generates all defaults)
	docker compose exec --user "$$(id -u):$$(id -g)" php php bin/console.php app:setup $(ARGS)

doctor: ## Inspect deployment readiness without changing application state
	$(MAKE) composer ARGS='doctor -- $(ARGS)'

prune-checks: ## Preview old history (ARGS='--apply' to delete; see --help)
	$(MAKE) composer ARGS='prune-checks -- $(ARGS)'

.PHONY: architecture

architecture: ## Enforce domain, application, HTTP, and infrastructure boundaries
	$(MAKE) composer ARGS='architecture'
