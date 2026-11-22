<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Exception\InvalidBlacklistEntryException;
use App\Domain\Permit\Validator\BlacklistEntryValidator;
use App\Domain\Permit\Validator\PermitIdValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[Small]
#[UsesClass(PermitIdValidator::class)]
#[CoversClass(BlacklistEntryValidator::class)]
final class BlacklistEntryValidatorTest extends TestCase
{
    public function testBoundaryLengthsAndExactNonEmptyIdsAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();
        $validator = new BlacklistEntryValidator(new PermitIdValidator());
        $validator->validate(id: str_repeat('a', 255), reason: str_repeat('r', 2000));
        $validator->validate(id: ' 0 ', reason: '   ');
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesAreRejected(string $id, string $reason): void
    {
        $this->expectException(InvalidBlacklistEntryException::class);
        new BlacklistEntryValidator(new PermitIdValidator())->validate(id: $id, reason: $reason);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEntries(): iterable
    {
        yield 'empty ID' => ['', ''];
        yield 'whitespace ID' => ['   ', ''];
        yield 'long ID' => [str_repeat('a', 256), ''];
        yield 'UTF-8 byte limit' => [str_repeat('ā', 128), ''];
        yield 'oversized whitespace reason' => ['id', str_repeat(' ', 2001)];
        yield 'long reason' => ['id', str_repeat('r', 2001)];
        yield 'control character' => ["id\n", ''];
        yield 'null byte' => ["id\0", ''];
    }
}
