#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php bin/check-assets.php

release_work=$(mktemp -d)
trap 'rm -rf "$release_work"' EXIT
release_source="$release_work/app"
mkdir -p "$release_source/bin"

# Only runtime source and deployment instructions enter the staging directory.
cp -R bootstrap config migrations public src templates translations "$release_source/"
cp .htaccess composer.json composer.lock README.md LICENSE "$release_source/"
cp bin/check-platform.php bin/smoke-test.php bin/console.php bin/warm-cache.php "$release_source/bin/"
cp -R docs "$release_source/"

if find "$release_source" -type l -print -quit | grep -q .; then
    echo 'Release source must not contain symlinks.' >&2
    exit 1
fi

composer install --working-dir="$release_source" --no-dev --no-scripts --no-plugins \
    --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
composer check-platform-reqs --working-dir="$release_source" --no-dev
php "$release_source/bin/check-platform.php"

mkdir -p dist
# OPcache treats timestamp 0 as a failed stat; retain reproducibility with a nonzero epoch.
tar --sort=name --mtime='@946684800' --owner=0 --group=0 --numeric-owner \
    -C "$release_source" -cf - . | gzip -n > "$release_work/permit-manager.tar.gz"
mv "$release_work/permit-manager.tar.gz" dist/permit-manager.tar.gz
(cd dist && sha256sum permit-manager.tar.gz > permit-manager.tar.gz.sha256)
echo 'Release created: dist/permit-manager.tar.gz'
