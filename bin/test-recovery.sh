#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Invoked only inside test-release.sh's disposable Compose project and temporary directory.
recovery_project=${1:?Disposable release project required}
recovery_work=${2:?Temporary release directory required}
if [[ "$recovery_project" != permit-release-* || "$recovery_work" != "$RELEASE_DIRECTORY" ]]; then
    echo 'Recovery testing requires the disposable release environment.' >&2
    exit 1
fi
umask 077
compose_recovery() {
    docker compose --env-file /dev/null -p "$recovery_project" -f compose.release.yaml "$@"
}
compose_recovery exec -T php php /run/recovery-fixtures.php --snapshot > "$recovery_work/recovery-before.json"
compose_recovery exec -T -e MYSQL_PWD=permits_test mysql-test \
    mysqldump -upermits_test --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
    --hex-blob permits_test > "$recovery_work/database.sql"
tar -C "$recovery_work/shared" -cf "$recovery_work/private.tar" .
(cd "$recovery_work" && sha256sum database.sql private.tar > recovery.sha256 && sha256sum -c recovery.sha256)

# Restore into a new, empty database; retain the source database for comparison and rollback checks.
compose_recovery exec -T -e MYSQL_PWD=test_root_only mysql-test mysql -uroot -e \
    "CREATE DATABASE permits_restored CHARACTER SET utf8mb4; GRANT ALL ON permits_restored.* TO 'permits_test'@'%';"
compose_recovery exec -T -e MYSQL_PWD=permits_test mysql-test \
    mysql -upermits_test permits_restored < "$recovery_work/database.sql"
mv "$recovery_work/shared" "$recovery_work/shared-before-recovery"
mkdir -m 755 "$recovery_work/shared"
tar -C "$recovery_work/shared" -xpf "$recovery_work/private.tar"
compose_recovery exec -T -e DB_DATABASE=permits_restored php php /run/recovery-fixtures.php \
    --verify /var/www/releases/recovery-before.json

# Invalidate restored login state without rotating credentials or either permit signing key.
compose_recovery exec -T -e MYSQL_PWD=permits_test mysql-test mysql -upermits_test permits_restored -e \
    'DELETE FROM admin_sessions; DELETE FROM admin_login_attempts;'
export RELEASE_DB_DATABASE=permits_restored
compose_recovery up -d --no-deps --force-recreate --wait php
compose_recovery exec -T php php vendor/bin/doctrine-migrations migrate --no-interaction --allow-no-migration
compose_recovery exec -T php php bin/console.php checks:prune --dry-run
compose_recovery exec -T php php bin/smoke-test.php http://127.0.0.1 /var/www/releases/checks.json
compose_recovery exec -T php php /run/release-fixtures.php --verify
export RELEASE_DB_DATABASE=permits_test
compose_recovery up -d --no-deps --force-recreate --wait php
echo 'Recovery verified: SQL dump, private files, unchanged signing keys, and legacy/HMAC HTTP checks.'
