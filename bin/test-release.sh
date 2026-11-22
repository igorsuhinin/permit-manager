#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

release_work=$(mktemp -d)
release_project="permit-release-$(id -u)-$$"
export RELEASE_DIRECTORY="$release_work"
export RELEASE_PUBLIC_DIRECTORY=/var/www/releases/current/public
export RELEASE_APP_ROOT=
export RELEASE_DB_DATABASE=permits_test
export RELEASE_TWIG_CACHE_VERSION=

compose_release() {
    docker compose --env-file /dev/null -p "$release_project" -f compose.release.yaml "$@"
}
cleanup_release() {
    cleanup_status=$?
    trap - EXIT
    compose_release down --volumes || cleanup_status=1
    rm -rf "$release_work"
    exit "$cleanup_status"
}
trap cleanup_release EXIT

chmod 755 "$release_work"
mkdir "$release_work/release-a" "$release_work/release-b" "$release_work/public_html"
mkdir -p "$release_work/shared/templates"
cat > "$release_work/shared/public-ui.php" <<'PHP'
<?php return ['messages' => ['check.title' => 'Recovery fixture']];
PHP
cat > "$release_work/shared/initial-blacklist.php" <<'PHP'
<?php return ['DEMO-RECOVERY-SEED'];
PHP
printf '.wrapper { border-color: #000; }\n' > "$release_work/shared/templates/permit.css.twig"
printf '.wrapper { border-color: #000; } /* cache-marker-a */\n' \
    > "$release_work/shared/templates/public-check.css.twig"
(cd dist && sha256sum -c permit-manager.tar.gz.sha256)
tar --no-same-owner -xzf dist/permit-manager.tar.gz -C "$release_work/release-a"
cmp LICENSE "$release_work/release-a/LICENSE"
cp -R "$release_work/release-a/." "$release_work/release-b/"
cp -R "$release_work/release-a/public/." "$release_work/public_html/"
# A harmless asset difference lets the smoke checks detect a stale candidate after rollback.
printf '\n/* Release candidate fixture. */\n' >> "$release_work/release-b/public/permit/style.css"
ln -s release-a "$release_work/current"

compose_release up -d --build --wait
compose_release exec -T php chown www-data:www-data /var/cache/permit-twig
compose_release exec -T php php bin/check-platform.php
if compose_release exec -T php php -n bin/check-platform.php > "$release_work/platform-negative.log" 2>&1; then
    echo 'The platform check unexpectedly accepted missing PHP extensions.' >&2
    exit 1
fi
compose_release exec -T php php vendor/bin/doctrine-migrations migrate --no-interaction --allow-no-migration
compose_release exec -T php php /run/recovery-fixtures.php --snapshot > "$release_work/doctor-before.json"
compose_release exec -T php php bin/console.php app:doctor --no-interaction --no-ansi
# Break only this extracted fixture's logger; the doctor must still report safely.
cp "$release_work/release-a/config/logger.php" "$release_work/doctor-logger.php"
printf "<?php throw new RuntimeException('synthetic-private-logger-error');\n" \
    > "$release_work/release-a/config/logger.php"
if compose_release exec -T php php bin/console.php app:doctor --no-interaction --no-ansi -vvv \
    > "$release_work/doctor-logger.log" 2>&1; then
    echo 'The doctor unexpectedly accepted a broken logger configuration.' >&2
    exit 1
fi
grep -Eq '^ *FAIL +Logger ' "$release_work/doctor-logger.log"
if grep -q 'synthetic-private-logger-error' "$release_work/doctor-logger.log"; then
    echo 'The doctor exposed a private logger error.' >&2
    exit 1
fi
cp "$release_work/doctor-logger.php" "$release_work/release-a/config/logger.php"
compose_release exec -T php php /run/recovery-fixtures.php --snapshot > "$release_work/doctor-after.json"
cmp "$release_work/doctor-before.json" "$release_work/doctor-after.json"
echo 'Doctor verified on the read-only release: safe diagnostics and unchanged database/private files.'
touch -d @0 "$release_work/release-a/public/index.php"
if compose_release exec -T php php /run/cache-fixtures.php --path > "$release_work/zero-timestamp.log" 2>&1; then
    echo 'Release verification unexpectedly accepted an uncacheable zero timestamp.' >&2
    exit 1
fi
touch -d @946684800 "$release_work/release-a/public/index.php"
cache_a=$(compose_release exec -T php php /run/cache-fixtures.php --path)
compose_release exec --user www-data -e DB_HOST=unavailable.invalid -T php php bin/warm-cache.php
compose_release exec -T php php /run/recovery-fixtures.php --snapshot > "$release_work/warm-after.json"
cmp "$release_work/doctor-before.json" "$release_work/warm-after.json"
if compose_release exec --user www-data -e TWIG_CACHE_DIRECTORY=/proc/blocked-cache -T php \
    php bin/warm-cache.php > "$release_work/warm-failure.log" 2>&1; then
    echo 'Template warmup unexpectedly accepted an unwritable cache.' >&2
    exit 1
fi
grep -q 'Template cache warmup failed' "$release_work/warm-failure.log"
if grep -q '/proc/blocked-cache' "$release_work/warm-failure.log"; then
    echo 'Template warmup exposed private cache configuration.' >&2
    exit 1
fi
compose_release exec -T php php /run/release-fixtures.php > "$release_work/checks.json"

smoke_release() {
    compose_release exec -T php php bin/smoke-test.php http://127.0.0.1 /var/www/releases/checks.json
}
smoke_release
compose_release exec -T php php /run/cache-fixtures.php a
bash bin/test-recovery.sh "$release_project" "$release_work"
for release_version in release-b release-a; do
    cache_marker="${release_version#release-}"
    printf '.wrapper { border-color: #000; } /* cache-marker-%s */\n' "$cache_marker" \
        > "$release_work/shared/templates/public-check.css.twig"
    ln -s "$release_version" "$release_work/next"
    mv -Tf "$release_work/next" "$release_work/current"
    # Recreate only PHP, equivalent to refreshing workers/OPcache on the host.
    compose_release up -d --no-deps --force-recreate --wait php
    cache_selected=$(compose_release exec -T php php /run/cache-fixtures.php --path)
    if [[ "$release_version" == release-b && "$cache_a" == "$cache_selected" ]]; then
        echo 'Physical releases unexpectedly share a template cache namespace.' >&2
        exit 1
    fi
    if [[ "$release_version" == release-a && "$cache_a" != "$cache_selected" ]]; then
        echo 'Rollback did not restore its template cache namespace.' >&2
        exit 1
    fi
    compose_release exec --user www-data -T php php bin/warm-cache.php
    smoke_release
    compose_release exec -T php php /run/cache-fixtures.php "$cache_marker"
    compose_release exec -T php php /run/release-fixtures.php --verify
done

# Fixed public_html layout: public files and the private application have separate parents.
export RELEASE_PUBLIC_DIRECTORY=/var/www/releases/public_html
export RELEASE_APP_ROOT=/var/www/releases/current
compose_release up -d --no-deps --force-recreate --wait php
smoke_release
compose_release exec -T php php /run/cache-fixtures.php a

# Private overrides edited in place use a new explicit namespace, without clearing rollback caches.
printf '.wrapper { border-color: #000; } /* cache-marker-b */\n' \
    > "$release_work/shared/templates/public-check.css.twig"
export RELEASE_TWIG_CACHE_VERSION=override-update
compose_release up -d --no-deps --force-recreate --wait php
cache_override=$(compose_release exec -T php php /run/cache-fixtures.php --path)
if [[ "$cache_override" == "$cache_a" ]]; then
    echo 'A template version change unexpectedly reused its cache namespace.' >&2
    exit 1
fi
compose_release exec --user www-data -T php php bin/warm-cache.php
compose_release exec -T php php /run/cache-fixtures.php b

# Verify the public startup boundary when the private application is misconfigured.
export RELEASE_PUBLIC_DIRECTORY=/var/www/releases/current/public
export RELEASE_APP_ROOT=/var/www/releases/missing
compose_release up -d --no-deps --force-recreate php
compose_release exec -T php php /run/release-fixtures.php --startup-error

# A wrongly selected private application root must fail closed.
export RELEASE_PUBLIC_DIRECTORY=/var/www/releases/current
export RELEASE_APP_ROOT=
compose_release up -d --no-deps --force-recreate php
compose_release exec -T php php /run/release-fixtures.php --wrong-root
if compose_release exec -T php php bin/smoke-test.php http://127.0.0.1 > "$release_work/wrong-root.log" 2>&1; then
    echo 'The wrong document root unexpectedly passed smoke checks.' >&2
    exit 1
fi
echo 'Release verified: production dependencies, Apache routes, private files, cPanel layout, and application rollback.'
