<?php

declare(strict_types=1);

use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Logging\SentryIntegration;
use App\Infrastructure\Logging\SentryPrivacy;
use Monolog\Level;

// null uses PHP's error_log destination (Docker logs locally).
// On cPanel, optionally use __DIR__ . '/../protected/logs/app.log'.
$logger = new LoggerFactory()->create(path: null, level: Level::Info, retention: 14);
/** @var array<string, mixed> $sentry */
$sentry = require __DIR__ . '/sentry.php';

return new SentryIntegration(new SentryPrivacy(dirname(__DIR__)))->configure($logger, $sentry);
