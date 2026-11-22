<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Logger;
use Sentry\ClientBuilder;
use Sentry\Dsn;
use Sentry\SentrySdk;

final readonly class SentryIntegration
{
    public function __construct(private SentryPrivacy $privacy) {}

    /** @param array<string, mixed> $options */
    public function configure(Logger $logger, array $options): Logger
    {
        if (null === ($options['dsn'] ?? null) || '' === $options['dsn']) {
            return $logger;
        }
        try {
            if (!is_string($options['dsn'])) {
                throw new \InvalidArgumentException('Sentry DSN must be a string.');
            }
            Dsn::createFromString($options['dsn']);
            $client = ClientBuilder::create(array_replace($options, [
                'default_integrations' => false,
                'send_default_pii' => false,
                'attach_stacktrace' => false,
                'context_lines' => 0,
                'max_breadcrumbs' => 0,
                'server_name' => '',
                'traces_sample_rate' => 0.0,
                'profiles_sample_rate' => 0.0,
                'profile_session_sample_rate' => 0.0,
                'http_connect_timeout' => 1,
                'http_timeout' => 2,
                'before_send' => $this->privacy->event(...),
                'before_send_log' => $this->privacy->log(...),
            ]))->getClient();
            $hub = SentrySdk::init();
            $hub->bindClient($client);
            $logger->pushHandler(new SentryHandler($hub));
        } catch (\Throwable $exception) {
            error_log('Sentry could not be configured; error_type=' . $exception::class);
        }

        return $logger;
    }

    public static function flush(): void
    {
        if (!class_exists(SentrySdk::class, false)) {
            return;
        }
        $client = SentrySdk::getCurrentHub()->getClient();
        if (null === $client) {
            return;
        }
        try {
            \Sentry\logger()->flush();
            $client->flush(2);
        } catch (\Throwable $exception) {
            error_log('Sentry flush failed; error_type=' . $exception::class);
        }
    }
}
