<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Logging\RequestLogContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(RequestLogContext::class)]
final class RequestLogContextTest extends TestCase
{
    public function testScopesGenerateFreshIdsAndRestoreParentWithoutLeaking(): void
    {
        $context = new RequestLogContext(str_repeat('a', 32));
        self::assertNull($context->requestId());
        $first = $context->begin();
        self::assertSame(str_repeat('a', 32), $first);
        $nested = $context->begin();
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $nested);
        self::assertNotSame($first, $nested);
        self::assertSame($nested, $context->requestId());
        $context->end();
        self::assertSame($first, $context->requestId());
        $context->end();
        self::assertNull($context->requestId());
        self::assertNotSame($first, $context->begin());
        $context->end();
    }

    #[DataProvider('invalidIds')]
    public function testOnlyTrustedInternalIdFormatCanBeSeeded(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RequestLogContext($id);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 31)];
        yield 'long' => [str_repeat('a', 33)];
        yield 'uppercase' => [str_repeat('A', 32)];
        yield 'nonhex' => [str_repeat('z', 32)];
        yield 'newline' => [str_repeat('a', 32) . "\n"];
    }

    public function testUnbalancedScopeIsAnExplicitProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        new RequestLogContext()->end();
    }
}
