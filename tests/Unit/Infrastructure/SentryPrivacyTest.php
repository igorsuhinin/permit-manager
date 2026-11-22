<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Logging\SentryPrivacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\Stacktrace;

#[Small]
#[CoversClass(SentryPrivacy::class)]
final class SentryPrivacyTest extends TestCase
{
    public function testRemoteStackRetainsCodeLocationWithoutArgumentsSourceOrPrivatePaths(): void
    {
        $frame = new Frame(
            functionName: 'Example::check',
            file: '/private/account/project/src/Example.php',
            line: 42,
            absoluteFilePath: '/private/account/project/src/Example.php',
            vars: ['password' => 'private-argument'],
        );
        $frame->setContextLine('private-source')->setPreContext(['private-before'])->setPostContext(['private-after']);
        $external = new Frame(functionName: 'private@anonymous/path', file: '/private/outside.php', line: 2);
        $exception = new ExceptionDataBag(
            exception: new \RuntimeException('private-message'),
            stacktrace: new Stacktrace([$frame, $external]),
            mechanism: new ExceptionMechanism(type: 'generic', handled: true, data: ['sql' => 'private-sql']),
        );
        $exception->setType('anonymous/private/file.php');
        $event = Event::createEvent()->setExceptions([$exception])
            ->setRequest(['url' => 'https://private.example.invalid/?sign=private-signature'])
            ->setContext(name: 'private-context', data: ['secret' => 'private-context-value'])
            ->setExtra(['monolog.context' => ['request_id' => str_repeat('a', 32)], 'private' => 'private-extra']);
        $clean = new SentryPrivacy('/private/account/project')->event($event);
        $copy = $clean->getExceptions()[0];
        self::assertSame($event->getId(), $clean->getId());
        self::assertSame([], $clean->getRequest());
        self::assertSame([], $clean->getContexts());
        self::assertSame('Throwable', $copy->getType());
        self::assertNull($copy->getMechanism());
        self::assertNotNull($copy->getStacktrace());
        $frames = $copy->getStacktrace()->getFrames();
        self::assertSame('src/Example.php', $frames[0]->getFile());
        self::assertSame('Example::check', $frames[0]->getFunctionName());
        self::assertSame(42, $frames[0]->getLine());
        self::assertNull($frames[0]->getAbsoluteFilePath());
        self::assertNull($frames[0]->getContextLine());
        self::assertSame([], $frames[0]->getVars());
        self::assertSame([], $frames[0]->getPreContext());
        self::assertSame([], $frames[0]->getPostContext());
        self::assertSame('[external]', $frames[1]->getFile());
        self::assertSame('[anonymous]', $frames[1]->getFunctionName());
        self::assertSame(['password' => 'private-argument'], $frame->getVars());
        self::assertSame('private-message', $exception->getValue());
    }

    public function testUnexpectedMetadataAndDynamicLogBodyAreRemoved(): void
    {
        $log = new Log(timestamp: 1.0, traceId: str_repeat('a', 32), level: LogLevel::info(), body: 'private-body');
        foreach (
            [
                'request_id' => 'private-request-id', 'error_type' => 'private/type', 'status' => 600,
                'method' => 'private-method', 'guest' => 'private-value', 'server.address' => 'private-host',
            ] as $key => $value
        ) {
            $log->setAttribute(key: $key, value: $value);
        }
        new SentryPrivacy('/application')->log($log);
        self::assertSame([], $log->attributes()->all());
        self::assertStringNotContainsString('private', $log->getBody());
    }
}
