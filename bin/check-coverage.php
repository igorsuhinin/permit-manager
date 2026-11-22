<?php

declare(strict_types=1);

if ('cli' !== PHP_SAPI) {
    http_response_code(404);
    exit(1);
}

$minimum = 95;
/**
 * The CLI operator selects a local report; realpath rejects stream wrappers.
 *
 * @psalm-taint-escape file
 * @psalm-taint-escape ssrf
 * @psalm-taint-escape url_component
 * @psalm-taint-escape url_path
 */
$report = realpath($argv[1] ?? __DIR__ . '/../.phpunit.cache/coverage/clover.xml');
// Read independently of libxml's entity-loader settings (PHP bug #62577).
$contents = false !== $report && is_file($report) && is_readable($report) ? file_get_contents($report) : false;
if (false === $contents) {
    fwrite(stream: STDERR, data: "Coverage report is missing or unreadable.\n");
    exit(1);
}

$previous = libxml_use_internal_errors(true);
$xml = simplexml_load_string(data: $contents, options: LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$metrics = false === $xml ? false : $xml->xpath('/coverage/project/metrics');
if (!is_array($metrics) || 1 !== count($metrics) || !isset($metrics[0])) {
    fwrite(stream: STDERR, data: "Coverage report has no valid project metrics.\n");
    exit(1);
}

$options = ['options' => ['min_range' => 0, 'max_range' => intdiv(PHP_INT_MAX, 100)]];
$total = filter_var((string) $metrics[0]['statements'], FILTER_VALIDATE_INT, $options);
$covered = filter_var((string) $metrics[0]['coveredstatements'], FILTER_VALIDATE_INT, $options);
if (false === $total || false === $covered || 0 === $total || $covered > $total) {
    fwrite(stream: STDERR, data: "Coverage report has invalid or empty line counts.\n");
    exit(1);
}

$message = sprintf(
    "Total line coverage: %.4f%% (%d/%d), minimum: %d%%.\n",
    100 * $covered / $total,
    $covered,
    $total,
    $minimum,
);
if ($covered * 100 < $total * $minimum) {
    fwrite(stream: STDERR, data: 'Coverage is below the required minimum. ' . $message);
    exit(1);
}

fwrite(stream: STDOUT, data: $message);
