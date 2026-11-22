<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit(1);
}

try {
    /**
     * This CLI tool intentionally probes the operator-selected HTTP(S) origin, validated below.
     *
     * @psalm-taint-escape ssrf
     * @psalm-taint-escape url_component
     * @psalm-taint-escape url_path
     * @psalm-taint-escape file
     */
    $baseUrl = rtrim($argv[1] ?? '', '/');
    $origin = parse_url($baseUrl);
    if (
        !is_array($origin) || !isset($origin['host'], $origin['scheme'])
        || !in_array($origin['scheme'], ['http', 'https'], true)
        || [] !== array_intersect(['user', 'pass', 'query', 'fragment', 'path'], array_keys($origin))
    ) {
        throw new RuntimeException('Usage: php bin/smoke-test.php https://example.com [private-checks.json]');
    }
    /** @return array{status: int, headers: array<string, string>, body: string} */
    $requestIds = [];
    $request = static function (string $path) use ($baseUrl, &$requestIds): array {
        http_clear_last_response_headers();
        $body = @file_get_contents(filename: $baseUrl . $path, context: stream_context_create(['http' => [
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
        ]]));
        /** @var list<string> $lines */
        $lines = http_get_last_response_headers() ?? [];
        if (
            false === $body || 1 !== preg_match(
                pattern: '/^HTTP\/\S+ (\d{3})/',
                subject: $lines[0] ?? '',
                matches: $m,
            )
        ) {
            throw new RuntimeException('Could not obtain an HTTP response. Check the origin and server logs.');
        }
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(separator: ':', string: $line, limit: 2);
            if (2 === count($parts)) {
                $headers[strtolower($parts[0])] = trim($parts[1]);
            }
        }
        foreach (
            [
                'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff', 'x-frame-options' => 'DENY',
            ] as $name => $value
        ) {
            if (($headers[$name] ?? '') !== $value) {
                throw new RuntimeException('Missing or inconsistent HTTP security header: ' . $name);
            }
        }
        if (
            !in_array((int) $m[1], [301, 308, 403], true)
            && (str_starts_with(haystack: $headers['content-type'] ?? '', needle: 'text/html') || '/health' === $path)
        ) {
            $requestId = $headers['x-request-id'] ?? '';
            if (
                1 !== preg_match(pattern: '/\A[a-f0-9]{32}\z/', subject: $requestId) || isset($requestIds[$requestId])
            ) {
                throw new RuntimeException('Missing, invalid, or reused server request ID.');
            }
            $requestIds[$requestId] = true;
            if (
                'no-store' !== ($headers['cache-control'] ?? '')
                || !str_contains(haystack: $headers['content-security-policy'] ?? '', needle: "default-src 'none'")
            ) {
                throw new RuntimeException('Missing dynamic response cache policy or CSP.');
            }
        }

        return ['status' => (int) $m[1], 'headers' => $headers, 'body' => $body];
    };
    $check = static function (bool $valid, string $label): void {
        if (!$valid) {
            throw new RuntimeException('Failed: ' . $label);
        }
        echo 'OK: ' . $label . "\n";
    };
    $health = $request('/health');
    $check(
        200 === $health['status'] && '{"status":"ok"}' === $health['body']
            && str_starts_with(haystack: $health['headers']['content-type'] ?? '', needle: 'application/json'),
        'HTTP entry point and liveness',
    );
    foreach (['/admin', '/admin/permits/generate', '/admin/permits'] as $path) {
        $response = $request($path);
        $check(
            303 === $response['status'] && '/admin/login' === ($response['headers']['location'] ?? ''),
            'Unauthenticated admin access: ' . $path,
        );
    }
    foreach (['/permit', '/permit/', '/permit/index.php'] as $path) {
        $response = $request($path);
        if (
            '/permit' === $path && 301 === $response['status']
            && '/permit/' === parse_url($response['headers']['location'] ?? '', PHP_URL_PATH)
        ) {
            $response = $request('/permit/');
        }
        $check(
            303 === $response['status'] && '/admin/permits/generate' === ($response['headers']['location'] ?? ''),
            'Legacy generator redirect: ' . $path,
        );
    }
    $missing = $request('/non-existing-page');
    $check(
        404 === $missing['status'] && str_contains(haystack: $missing['body'], needle: '404 Not Found')
            && !str_contains(haystack: $missing['body'], needle: 'Stack trace'),
        'Generic missing-page response',
    );
    foreach (
        [
            '/.env', '/.git/config', '/composer.json', '/composer.lock', '/vendor/autoload.php',
            '/config/database.php', '/protected/public-ui.php', '/src/Domain/Permit/Service/PermitSigner.php',
            '/docs/deployment.md', '/bin/reset-db.php', '/compose.test.yaml', '/phpunit.mysql.xml.dist',
            '/IMPROVEMENT_PLAN.md', '/dist/permit-manager.tar.gz', '/public/index.php',
        ] as $path
    ) {
        $check(in_array($request($path)['status'], [403, 404], true), 'Private-file isolation: ' . $path);
    }
    $public = __DIR__ . '/../public/';
    $assets = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $public,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($assets as $file) {
        if (
            !$file instanceof SplFileInfo || !$file->isFile() || in_array($file->getFilename(), [
                'index.php', '.htaccess',
            ], true)
        ) {
            continue;
        }
        $path = substr(string: $file->getPathname(), offset: strlen($public));
        $asset = $request('/' . $path);
        $check(
            200 === $asset['status'] && '' !== $asset['body']
                && hash_file(algo: 'sha256', filename: $file->getPathname())
                    === hash(algo: 'sha256', data: $asset['body']),
            'Public asset: ' . $path,
        );
    }
    if (isset($argv[2])) {
        /**
         * The operator selects a local private fixture; realpath rejects stream wrappers.
         *
         * @psalm-taint-escape file
         * @psalm-taint-escape ssrf
         * @psalm-taint-escape url_component
         * @psalm-taint-escape url_path
         */
        $checksPath = realpath($argv[2]);
        $contents = false === $checksPath ? false : file_get_contents($checksPath);
        $checks = false === $contents ? null : json_decode(
            json: $contents,
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($checks) || !array_is_list($checks) || [] === $checks) {
            throw new RuntimeException('Private checks must be a non-empty JSON list.');
        }
        /** @var mixed $case */
        foreach ($checks as $index => $case) {
            $query = is_array($case) ? ($case['query'] ?? null) : null;
            $contains = is_array($case) ? ($case['contains'] ?? null) : null;
            if (
                !is_array($query) || [] === $query || !is_string($contains) || '' === $contains
            ) {
                throw new RuntimeException('Each private check needs query fields and expected response text.');
            }
            $parameters = [];
            /** @var mixed $value */
            foreach ($query as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new RuntimeException('Private query keys and values must be strings.');
                }
                $parameters[$key] = $value;
            }
            foreach (['/p/check', '/p/check.php'] as $path) {
                $response = $request($path . '?' . http_build_query($parameters));
                $check(
                    200 === $response['status'] && str_contains(
                        haystack: $response['body'],
                        needle: $contains,
                    ),
                    'Private permit check ' . ($index + 1) . ': ' . $path,
                );
            }
        }
    }
    echo "Deployment smoke checks passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
