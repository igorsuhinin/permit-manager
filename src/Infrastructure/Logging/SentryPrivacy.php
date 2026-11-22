<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Sentry\Event;
use Sentry\Frame;
use Sentry\Logs\Log;
use Sentry\Stacktrace;

final readonly class SentryPrivacy
{
    private const array MESSAGES = [
        'HTTP request failed', 'Application startup failed', 'Permit generated', 'Permit reprinted',
        'Invalid permit request', 'Admin blacklist changed', 'Admin login succeeded', 'Admin login failed',
        'Admin logged out', 'Admin CSRF validation failed', 'Unable to store permit check history',
        'Permit check history maintenance completed', 'Permit check history maintenance failed',
    ];

    public function __construct(private string $rootDirectory) {}

    public function event(Event $event): Event
    {
        $extra = $event->getExtra();
        /** @psalm-var mixed $context */
        $context = $extra['monolog.context'] ?? null;
        $metadata = $this->metadata(is_array($context) ? $context : []);
        $clean = Event::createEvent($event->getId());
        $clean->setLevel($event->getLevel())->setTimestamp($event->getTimestamp())
            ->setEnvironment($event->getEnvironment())->setRelease($event->getRelease())
            ->setMessage($this->message($extra['monolog.message'] ?? null))->setExtra($metadata);
        if (isset($metadata['request_id']) && is_string($metadata['request_id'])) {
            $clean->setTag(key: 'request_id', value: $metadata['request_id']);
        }
        $exceptions = [];
        foreach ($event->getExceptions() as $exception) {
            $copy = clone $exception;
            $copy->setValue('Exception details are retained in private application logs.')->setMechanism(null);
            if (1 !== preg_match(pattern: '/\A[A-Za-z0-9_\\\\]+\z/', subject: $copy->getType())) {
                $copy->setType('Throwable');
            }
            $stack = $copy->getStacktrace();
            if (null !== $stack) {
                $frames = [];
                foreach ($stack->getFrames() as $frame) {
                    $file = $frame->getAbsoluteFilePath() ?? $frame->getFile();
                    $file = str_starts_with(haystack: $file, needle: $this->rootDirectory . '/')
                        ? substr(string: $file, offset: strlen($this->rootDirectory) + 1) : '[external]';
                    $function = $frame->getFunctionName();
                    if (
                        null !== $function
                        && 1 !== preg_match(pattern: '/\A[A-Za-z0-9_\\\\:>]+\z/', subject: $function)
                    ) {
                        $function = '[anonymous]';
                    }
                    $frames[] = new Frame(
                        functionName: $function,
                        file: $file,
                        line: $frame->getLine(),
                        inApp: $frame->isInApp(),
                    );
                }
                $copy->setStacktrace(new Stacktrace($frames));
            }
            $exceptions[] = $copy;
        }
        $clean->setExceptions($exceptions);

        return $clean;
    }

    public function log(Log $log): Log
    {
        $metadata = $this->metadata($log->attributes()->toSimpleArray());
        foreach (array_keys($log->attributes()->all()) as $key) {
            $log->attributes()->forget($key);
        }
        foreach ($metadata as $key => $value) {
            $log->setAttribute(key: $key, value: $value);
        }
        $log->setBody($this->message($log->getBody()));

        return $log;
    }

    private function message(mixed $message): string
    {
        return is_string($message) && in_array($message, self::MESSAGES, true)
            ? $message : 'Application log; details are retained locally.';
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<string, string|int|bool>
     */
    private function metadata(array $context): array
    {
        $safe = [];
        /** @psalm-var mixed $id */
        $id = $context['request_id'] ?? null;
        if (is_string($id) && 1 === preg_match(pattern: '/\A[a-f0-9]{32}\z/', subject: $id)) {
            $safe['request_id'] = $id;
        }
        /** @psalm-var mixed $type */
        $type = $context['error_type'] ?? null;
        if (is_string($type) && 1 === preg_match(pattern: '/\A[A-Za-z0-9_\\\\]+\z/', subject: $type)) {
            $safe['error_type'] = $type;
        }
        /** @psalm-var mixed $status */
        $status = $context['status'] ?? null;
        if (is_int($status) && $status >= 100 && $status <= 599) {
            $safe['status'] = $status;
        }
        /** @psalm-var mixed $method */
        $method = $context['method'] ?? null;
        if (in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            $safe['method'] = $method;
        }
        /** @psalm-var mixed $guest */
        $guest = $context['guest'] ?? null;
        if (is_bool($guest)) {
            $safe['guest'] = $guest;
        }

        return $safe;
    }
}
