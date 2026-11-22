<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Permit\Enum\PermitFailureReason;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Port\PermitBlacklistInterface;
use App\Domain\Permit\Service\PermitExpiration;
use App\Domain\Permit\Service\PermitSigner;
use App\Domain\Permit\Service\PermitVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[Small]
#[CoversClass(PermitVerifier::class)]
#[CoversClass(PermitExpiration::class)]
#[CoversClass(PermitSigner::class)]
#[UsesClass(Permit::class)]
final class PermitVerifierTest extends TestCase
{
    public function testLegacySignaturesRemainCompatible(): void
    {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $resident = new Permit(id: '26/1', term: '03.11.2026', plate: 'AB1234');
        $guest = new Permit(id: '26/2', term: '03.11.2026', guest: true, apartment: '48');
        self::assertSame('19337794d774e807fce07ff2b42320be', $signer->sign($resident));
        self::assertSame('3ae624f531b15f02a48fd4b002e58708', $signer->sign($guest));
        self::assertFalse($signer->matches($resident, ''));
        self::assertFalse($signer->matches(
            new Permit(id: 'changed', term: $resident->term, plate: $resident->plate),
            $signer->sign($resident),
        ));
    }

    public function testGuestSignatureUsesApartmentInsteadOfPlate(): void
    {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $permit = new Permit(id: '26/1', term: '03.11.2026', plate: 'ignored', guest: true, apartment: '48');
        self::assertTrue($signer->matches(
            new Permit(id: '26/1', term: '03.11.2026', plate: 'different', guest: true, apartment: '48'),
            $signer->sign($permit),
        ));
        self::assertFalse($signer->matches(
            new Permit(id: '26/1', term: '03.11.2026', plate: 'ignored', guest: true, apartment: '49'),
            $signer->sign($permit),
        ));
    }

    #[DataProvider('verificationCases')]
    public function testVerification(
        string $id,
        string $term,
        bool $correctSignature,
        string $now,
        ?PermitFailureReason $reason,
    ): void {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $blacklist = $this->createMock(PermitBlacklistInterface::class);
        $blacklist->expects($this->once())->method('contains')->with($id)->willReturn('blocked' === $id);
        $verifier = new PermitVerifier($signer, $blacklist, new \DateTimeZone('Europe/Riga'));
        $permit = new Permit(id: $id, term: $term, plate: 'AB1234');
        self::assertSame(
            $reason,
            $verifier->verify(
                $permit,
                $correctSignature ? $signer->sign($permit) : 'bad',
                new \DateTimeImmutable($now),
            ),
        );
    }

    /** @return iterable<string, array{string, string, bool, string, ?PermitFailureReason}> */
    public static function verificationCases(): iterable
    {
        yield 'valid' => ['26/1', '03.11.2026', true, '2026-11-03T12:00:00+02:00', null];
        yield 'last second' => ['26/1', '03.11.2026', true, '2026-11-03T23:59:59+02:00', null];
        yield 'midnight expired' => [
            '26/1',
            '03.11.2026',
            true,
            '2026-11-04T00:00:00+02:00',
            PermitFailureReason::Expired,
        ];
        yield 'Riga summer time' => ['26/1', '01.07.2026', true, '2026-07-01T21:00:00Z', PermitFailureReason::Expired];
        yield 'tampered' => ['26/1', '03.11.2026', false, '2026-11-03', PermitFailureReason::InvalidSignature];
        yield 'expiry overrides signature' => ['26/1', '01.01.2020', false, '2026-11-03', PermitFailureReason::Expired];
        yield 'revocation overrides expiry' => [
            'blocked',
            '01.01.2020',
            false,
            '2026-11-03',
            PermitFailureReason::Revoked,
        ];
        yield 'revoked with valid signature and date' => [
            'blocked',
            '03.11.2026',
            true,
            '2026-11-03',
            PermitFailureReason::Revoked,
        ];
        yield 'revocation overrides invalid date' => [
            'blocked',
            '31.02.2026',
            false,
            '2026-11-03',
            PermitFailureReason::Revoked,
        ];
        yield 'impossible date' => ['26/1', '31.02.2026', true, '2026-11-03', PermitFailureReason::InvalidExpiration];
        yield 'missing date' => ['26/1', '', true, '2026-11-03', PermitFailureReason::InvalidExpiration];
        yield 'null byte' => ['26/1', "03.11.2026\0", true, '2026-11-03', PermitFailureReason::InvalidExpiration];
    }

    public function testBlacklistLookupFailurePropagates(): void
    {
        $blacklist = self::createStub(PermitBlacklistInterface::class);
        $blacklist->method('contains')->willThrowException(new \RuntimeException('Blacklist lookup failed.'));
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $verifier = new PermitVerifier($signer, $blacklist, new \DateTimeZone('Europe/Riga'));
        $permit = new Permit(id: '26/1', term: '03.11.2026', plate: 'AB1234');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('Blacklist lookup failed.');
        $verifier->verify($permit, $signer->sign($permit), new \DateTimeImmutable('2026-11-03'));
    }
}
