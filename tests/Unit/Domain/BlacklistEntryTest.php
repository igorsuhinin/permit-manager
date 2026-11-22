<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Model\BlacklistEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(BlacklistEntry::class)]
final class BlacklistEntryTest extends TestCase
{
    public function testExactIdAndBoundaryLengthsArePreserved(): void
    {
        $id = str_repeat('a', 255);
        $reason = str_repeat('r', 2000);
        $entry = new BlacklistEntry(id: $id, reason: $reason);
        self::assertSame($id, $entry->id);
        self::assertSame($reason, $entry->reason);
        self::assertSame(' 0 ', new BlacklistEntry(' 0 ')->id);
        self::assertNull(new BlacklistEntry(id: '0', reason: '   ')->reason);
        self::assertSame(' reason ', new BlacklistEntry(id: '0', reason: ' reason ')->reason);
    }
}
