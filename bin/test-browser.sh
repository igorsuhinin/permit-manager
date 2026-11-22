#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

browser_mode='test'
if [[ "${1:-}" == --serve ]]; then
    browser_mode=demo
    shift
elif [[ "${1:-}" == --benchmark ]]; then
    browser_mode=benchmark
    shift
    benchmark_label="${1:-current}"
    if [[ ! "$benchmark_label" =~ ^[a-z0-9-]+$ ]]; then
        echo 'Benchmark labels must contain lowercase letters, digits, or hyphens.' >&2
        exit 1
    fi
fi
browser_work=$(mktemp -d)
browser_project="permit-browser-$(id -u)-$$"
export BROWSER_RELEASE_DIRECTORY="$browser_work"
export BROWSER_REPORT_DIRECTORY="$PWD/.phpunit.cache/browser"
BROWSER_UID="$(id -u)"
BROWSER_GID="$(id -g)"
export BROWSER_UID BROWSER_GID
browser_compose=(-f compose.browser.yaml)
if [[ "$browser_mode" == demo ]]; then
    browser_compose+=(-f compose.demo.yaml)
fi
compose_browser() {
    docker compose --env-file /dev/null -p "$browser_project" "${browser_compose[@]}" "$@"
}
cleanup_browser() {
    browser_status=$?
    trap - EXIT INT TERM
    compose_browser down --volumes || browser_status=1
    rm -rf "$browser_work"
    exit "$browser_status"
}
trap cleanup_browser EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

chmod 755 "$browser_work"
mkdir -p "$browser_work/app" "$browser_work/fixtures" "$browser_work/empty-templates" \
    "$BROWSER_REPORT_DIRECTORY" docs/screenshots
(cd dist && sha256sum -c permit-manager.tar.gz.sha256)
tar --no-same-owner -xzf dist/permit-manager.tar.gz -C "$browser_work/app"
if [[ "$browser_mode" == benchmark ]]; then
    cp tests/Support/runtime-probe.php "$browser_work/app/public/__benchmark.php"
fi
compose_browser up -d --build --wait php
compose_browser exec -T php php bin/check-platform.php
compose_browser exec -T php php vendor/bin/doctrine-migrations migrate --no-interaction
compose_browser exec -T php php /run/browser-fixtures.php > "$browser_work/fixtures/demo.json"
if [[ "$browser_mode" == benchmark ]]; then
    mkdir -p .phpunit.cache/benchmark
    benchmark_hash=$(sha256sum dist/permit-manager.tar.gz | cut -d ' ' -f 1)
    compose_browser exec -T php php /run/benchmark.php "$benchmark_hash" \
        > ".phpunit.cache/benchmark/$benchmark_label.json"
    echo "Benchmark report: .phpunit.cache/benchmark/$benchmark_label.json"
elif [[ "$browser_mode" == demo ]]; then
    printf '\nSynthetic demo: http://localhost:%s/admin\nLogin: admin / test-password\n' "${DEMO_HTTP_PORT:-18080}"
    echo 'Stop with Ctrl+C to remove this disposable environment and its data.'
    compose_browser exec -T php php /run/browser-fixtures.php --links
    compose_browser logs --follow php
else
    compose_browser build browser
    compose_browser run --rm --no-deps -T browser npm run check
    compose_browser run --rm --no-deps -T browser npm test -- "$@"
fi
