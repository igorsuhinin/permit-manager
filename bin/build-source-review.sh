#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

review_work=$(mktemp -d)
trap 'rm -rf "$review_work"' EXIT
review_source="$review_work/source"
mkdir "$review_source"
# Make supplies Git's NUL-delimited file list; the PHP image needs no Git client.
cat > "$review_work/files"

# Export current source without Git metadata or private state.
while IFS= read -r -d '' source_path; do
    case "$source_path" in
        .dockerignore|.editorconfig|.env.example|.gitattributes|.gitignore|.htaccess|.php-cs-fixer.dist.php|\
        Dockerfile|LICENSE|Makefile|README.md|assets.lock.json|\
        compose.yaml|compose.browser.yaml|compose.demo.yaml|compose.release.yaml|compose.test.yaml|\
        composer.json|composer.lock|deptrac.yaml|infection.json5|phpcs.xml.dist|phpstan.neon|\
        phpunit.xml.dist|phpunit.mysql.xml.dist|psalm.xml|rector.php|\
        .github/*|bin/*|bootstrap/*|config/*|docker/*|docs/*|migrations/*|public/*|src/*|templates/*|tests/*|translations/*)
            ;;
        *) continue ;;
    esac
    case "$source_path" in
        */node_modules/*) continue ;;
        */.env|*.log|*.sql|*.sqlite|*.sqlite3|*.pem|*.key)
            echo 'Source review refused a private-state file inside a source directory.' >&2
            exit 1 ;;
    esac
    if [[ -L "$source_path" || ! -f "$source_path" ]]; then
        echo 'Source review requires regular files; remove symlinks or stale index entries.' >&2
        exit 1
    fi
    source_parent=$(dirname "$source_path")
    while [[ "$source_parent" != . ]]; do
        if [[ -L "$source_parent" ]]; then
            echo 'Source review refused a symlink in a source path.' >&2
            exit 1
        fi
        source_parent=$(dirname "$source_parent")
    done
    mkdir -p "$review_source/$(dirname "$source_path")"
    cp "$source_path" "$review_source/$source_path"
done < "$review_work/files"

for required in LICENSE docs/publication.md composer.json composer.lock; do
    test -f "$review_source/$required"
done

mkdir -p dist
tar --sort=name --mtime='@946684800' --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' \
    -C "$review_source" -cf - . | gzip -n > "$review_work/source-review.tar.gz"
mv "$review_work/source-review.tar.gz" dist/permit-manager-source-review.tar.gz
(cd dist && sha256sum permit-manager-source-review.tar.gz > permit-manager-source-review.tar.gz.sha256)
echo 'Source review candidate created: dist/permit-manager-source-review.tar.gz'
echo 'Review only: unresolved asset rights and owner publication checks remain; see docs/publication.md.'
