<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Infection uses its own cache directory; prepare the fallback log independently.
$logDirectory = dirname(__DIR__) . '/.phpunit.cache';
if (!is_dir($logDirectory) && !mkdir(directory: $logDirectory, permissions: 0775, recursive: true)) {
    throw new RuntimeException('Could not create the test log directory.');
}
ini_set(option: 'error_log', value: $logDirectory . '/application.log');

// Make Doctrine's opt-in runtime deprecations visible to PHPUnit as well.
Doctrine\Deprecations\Deprecation::enableWithTriggerError();
