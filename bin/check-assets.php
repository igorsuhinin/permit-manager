<?php

declare(strict_types=1);

// Vendored files are reviewed and committed together with this offline integrity manifest.
try {
    $root = dirname(__DIR__);
    $contents = file_get_contents($root . '/assets.lock.json');
    /** @psalm-var mixed $manifest */
    $manifest = false === $contents ? null : json_decode(
        json: $contents,
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $managed = is_array($manifest) ? ($manifest['files'] ?? null) : null;
    $legacy = is_array($manifest) ? ($manifest['legacy_files'] ?? null) : null;
    if (!is_array($managed) || [] === $managed || !is_array($legacy) || [] === $legacy) {
        throw new RuntimeException('The frontend asset manifest is missing or invalid.');
    }
    $paths = [];
    /** @var mixed $file */
    foreach ([...$managed, ...$legacy] as $file) {
        $relative = is_array($file) ? ($file['path'] ?? null) : null;
        $hash = is_array($file) ? ($file['sha256'] ?? null) : null;
        if (
            !is_array($file) || !is_string($relative) || !is_string($hash)
            || 1 !== preg_match(pattern: '#\A(?:vendor|permit/assets)/#', subject: $relative)
            || str_contains(haystack: $relative, needle: '..')
            || 1 !== preg_match(pattern: '/^[a-f0-9]{64}$/D', subject: $hash)
            || !is_string($file['license'] ?? null) || !is_string($file['version'] ?? null)
            || isset($paths[$relative])
        ) {
            throw new RuntimeException('The frontend asset manifest contains an invalid entry.');
        }
        /** @psalm-var mixed $url */
        $url = $file['url'] ?? null;
        $unverified = str_starts_with(haystack: $relative, needle: 'permit/assets/')
            && 'NOASSERTION' === $file['license'] && null === $url;
        if (!$unverified && (!is_string($url) || !str_starts_with(haystack: $url, needle: 'https://'))) {
            throw new RuntimeException('The frontend asset manifest contains invalid provenance.');
        }
        $path = $root . '/public/' . $relative;
        if (is_link($path) || !is_file($path) || hash_file(algo: 'sha256', filename: $path) !== $hash) {
            throw new RuntimeException('Frontend asset missing or modified: ' . $relative);
        }
        $paths[$relative] = true;
    }
    foreach (['vendor', 'permit/assets'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/public/' . $directory,
            FilesystemIterator::SKIP_DOTS,
        ));
        /** @var SplFileInfo|string $file */
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $path = substr(string: $file->getPathname(), offset: strlen($root . '/public/'));
                if (!isset($paths[$path])) {
                    throw new RuntimeException('Unrecorded frontend asset: ' . $path);
                }
            }
        }
    }
    echo 'Frontend asset integrity verified (' . count($paths) . " files).\n";
} catch (Throwable $exception) {
    fwrite(stream: STDERR, data: $exception->getMessage() . "\n");
    exit(1);
}
