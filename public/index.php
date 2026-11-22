<?php

declare(strict_types=1);

// Keep the startup boundary safe even when the framework cannot be booted.
ini_set(option: 'display_errors', value: '0');
ini_set(option: 'display_startup_errors', value: '0');
ini_set(option: 'zend.exception_ignore_args', value: '1');
$logger = null;
$requestId = null;

try {
    $requestId = bin2hex(random_bytes(16));
    header('X-Request-ID: ' . $requestId);
    $applicationRoot = getenv('APP_ROOT');
    $applicationRoot = false === $applicationRoot || '' === $applicationRoot ? dirname(__DIR__) : $applicationRoot;
    if (!str_starts_with(haystack: $applicationRoot, needle: '/')) {
        throw new RuntimeException('APP_ROOT must be an absolute application directory.');
    }
    /** @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests. */
    require $applicationRoot . '/vendor/autoload.php';
    /**
     * @var Psr\Log\LoggerInterface $logger
     *
     * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
     */
    $logger = require $applicationRoot . '/config/logger.php';
    /**
     * @var Closure(
     *     ?App\Application\Configuration\Settings $settings,
     *     ?Psr\Log\LoggerInterface $logger,
     *     ?Doctrine\DBAL\Connection $connection,
     *     ?string $requestId
     * ): Slim\App<DI\Container> $createApp
     *
     * @psalm-suppress UnresolvableInclude Runtime-selected paths are exercised by integration tests.
     */
    $createApp = require $applicationRoot . '/bootstrap/app.php';
    $app = $createApp(settings: null, logger: $logger, connection: null, requestId: $requestId);
    $app->run();
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    echo '500 Internal Server Error';
    try {
        if (null !== $logger) {
            $logger->critical('Application startup failed', ['exception' => $exception, 'request_id' => $requestId]);
        } else {
            error_log('Application startup failed; request_id=' . ($requestId ?? 'unavailable') . '; '
                . $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':'
                . $exception->getLine());
        }
    } catch (Throwable) {
        error_log('Application startup failed; logger unavailable; request_id=' . ($requestId ?? 'unavailable') . '; '
            . $exception::class . ': '
            . $exception->getMessage()
            . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    }
} finally {
    if (class_exists(App\Infrastructure\Logging\SentryIntegration::class, false)) {
        App\Infrastructure\Logging\SentryIntegration::flush();
    }
}
