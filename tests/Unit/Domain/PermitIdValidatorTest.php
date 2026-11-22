<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Validator\PermitIdValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(PermitIdValidator::class)]
final class PermitIdValidatorTest extends TestCase
{
    #[DataProvider('ids')]
    public function testIssuanceAndBlacklistIdPolicy(string $id, bool $valid): void
    {
        self::assertSame($valid, new PermitIdValidator()->isValid($id));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function ids(): iterable
    {
        yield 'zero' => ['0', true];
        yield 'surrounding spaces' => [' 0 ', true];
        yield 'punctuation and Unicode' => ['A&Ā/Квартира-1', true];
        yield 'ASCII byte boundary' => [str_repeat('a', 255), true];
        yield 'Unicode byte boundary' => [str_repeat('ā', 127) . 'a', true];
        yield 'empty' => ['', false];
        yield 'spaces only' => ['   ', false];
        yield 'whitespace only' => [" \t\n\r\0\x0b", false];
        yield 'ASCII over byte limit' => [str_repeat('a', 256), false];
        yield 'Unicode over byte limit' => [str_repeat('ā', 128), false];
        foreach ([...range(0, 31), 127] as $code) {
            yield 'embedded control ' . $code => ['a' . chr($code) . 'b', false];
        }
    }
}
