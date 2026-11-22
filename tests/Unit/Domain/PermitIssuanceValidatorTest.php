<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Service\PermitExpiration;
use App\Domain\Permit\Validator\PermitIdValidator;
use App\Domain\Permit\Validator\PermitIssuanceValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[Small]
#[UsesClass(PermitIdValidator::class)]
#[CoversClass(PermitIssuanceValidator::class)]
#[UsesClass(Permit::class)]
#[UsesClass(PermitExpiration::class)]
#[UsesClass(InvalidPermitInputException::class)]
final class PermitIssuanceValidatorTest extends TestCase
{
    #[DataProvider('validPermits')]
    public function testValidClaimsReturnTheConfiguredEndOfDay(Permit $permit): void
    {
        $validator = new PermitIssuanceValidator(new PermitIdValidator(), new \DateTimeZone('Europe/Riga'));
        self::assertSame('2020-01-01T23:59:59+02:00', $validator->validate($permit)->format(DATE_ATOM));
    }

    /** @return iterable<string, array{Permit}> */
    public static function validPermits(): iterable
    {
        yield 'resident zero values and historical date' => [new Permit(id: '0', term: '01.01.2020', plate: '0')];
        yield 'guest requires apartment only' => [new Permit(
            id: 'DEMO-1',
            term: '01.01.2020',
            guest: true,
            apartment: '0',
        )];
        yield 'issuance preserves surrounding spaces' => [new Permit(id: ' 0 ', term: '01.01.2020', plate: 'AB1234')];
    }

    #[DataProvider('invalidPermits')]
    public function testInvalidClaimsRetainTheirFailurePriority(Permit $permit, PermitFailureReason $reason): void
    {
        $this->expectException(InvalidPermitInputException::class);
        $this->expectExceptionMessageIs($reason->value);
        new PermitIssuanceValidator(new PermitIdValidator(), new \DateTimeZone('Europe/Riga'))->validate($permit);
    }

    /** @return iterable<string, array{Permit, PermitFailureReason}> */
    public static function invalidPermits(): iterable
    {
        yield 'details precede invalid date' => [
            new Permit(id: '', term: 'invalid'),
            PermitFailureReason::InvalidDetails,
        ];
        foreach (['   ', str_repeat('a', 256), str_repeat('ā', 128), "a\nb", "a\0b", "a\x7fb"] as $id) {
            yield 'invalid ID ' . bin2hex($id) => [
                new Permit(id: $id, term: 'invalid', plate: 'AB1234'),
                PermitFailureReason::InvalidDetails,
            ];
        }
        yield 'resident needs plate' => [
            new Permit(id: 'DEMO-1', term: '01.01.2020', apartment: '1'),
            PermitFailureReason::InvalidDetails,
        ];
        yield 'guest needs apartment' => [
            new Permit(id: 'DEMO-1', term: '01.01.2020', plate: 'AB1234', guest: true),
            PermitFailureReason::InvalidDetails,
        ];
        yield 'invalid calendar day' => [
            new Permit(id: 'DEMO-1', term: '31.02.2026', plate: 'AB1234'),
            PermitFailureReason::InvalidExpiration,
        ];
        yield 'null byte in date' => [
            new Permit(id: 'DEMO-1', term: "01.01.2020\0", plate: 'AB1234'),
            PermitFailureReason::InvalidExpiration,
        ];
    }
}
