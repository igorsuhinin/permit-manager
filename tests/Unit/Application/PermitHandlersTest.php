<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Audit\AdminAudit;
use App\Application\Command\GeneratePermitCommand;
use App\Application\Command\GeneratePermitHandler;
use App\Application\Configuration\Settings;
use App\Application\Exception\PermitNotFoundException;
use App\Application\Port\AdminAuditTrailInterface;
use App\Application\Port\PermitLookupInterface;
use App\Application\Port\QrCodeGeneratorInterface;
use App\Application\Query\ReprintPermitHandler;
use App\Application\Query\ReprintPermitQuery;
use App\Application\Query\VerifyPermitHandler;
use App\Application\Query\VerifyPermitQuery;
use App\Application\Support\PrintablePermitFactory;
use App\Application\View\AdminAuditEntry;
use App\Application\View\PrintablePermit;
use App\Domain\Permit\Enum\PermitSignatureVersion;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Port\PermitBlacklistInterface;
use App\Domain\Permit\Port\PermitRepositoryInterface;
use App\Domain\Permit\Service\PermitSigner;
use App\Domain\Permit\Service\PermitVerifier;
use App\Domain\Permit\Validator\PermitIdValidator;
use App\Domain\Permit\Validator\PermitIssuanceValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\Support\FrozenClock;
use Tests\Support\ImmediateTransaction;

#[Small]
#[UsesClass(AdminAudit::class)]
#[UsesClass(AdminAuditEntry::class)]
#[UsesClass(PermitIdValidator::class)]
#[CoversClass(GeneratePermitHandler::class)]
#[CoversClass(ReprintPermitHandler::class)]
#[CoversClass(VerifyPermitHandler::class)]
#[CoversClass(PrintablePermitFactory::class)]
#[UsesClass(GeneratePermitCommand::class)]
#[UsesClass(ReprintPermitQuery::class)]
#[UsesClass(VerifyPermitQuery::class)]
#[UsesClass(Settings::class)]
#[UsesClass(PrintablePermit::class)]
#[UsesNamespace('App\\Domain')]
final class PermitHandlersTest extends TestCase
{
    private function audit(): AdminAudit
    {
        return new AdminAudit(
            self::createStub(AdminAuditTrailInterface::class),
            new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
        );
    }

    private function generateHandler(
        PermitRepositoryInterface $store,
        QrCodeGeneratorInterface $qr,
        LoggerInterface $logger,
    ): GeneratePermitHandler {
        $settings = new Settings(
            secret: 'test-secret',
            hmacKey: str_repeat('h', 32),
            passwordHash: 'unused',
            baseUrl: 'https://example.com',
        );

        return new GeneratePermitHandler(
            validator: new PermitIssuanceValidator(new PermitIdValidator(), new \DateTimeZone('Europe/Riga')),
            permits: $store,
            printing: new PrintablePermitFactory(
                new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32)),
                $qr,
                $settings,
            ),
            clock: new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
            audit: $this->audit(),
            transactions: new ImmediateTransaction(),
            logger: $logger,
        );
    }

    private function reprintHandler(PermitLookupInterface $store, QrCodeGeneratorInterface $qr): ReprintPermitHandler
    {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $blacklist = self::createStub(PermitBlacklistInterface::class);
        $blacklist->method('contains')->willReturn(false);

        return new ReprintPermitHandler(
            permits: $store,
            verifier: new PermitVerifier($signer, $blacklist, new \DateTimeZone('Europe/Riga')),
            signer: $signer,
            printing: new PrintablePermitFactory(
                $signer,
                $qr,
                new Settings(
                    secret: 'test-secret',
                    hmacKey: str_repeat('h', 32),
                    passwordHash: 'unused',
                    baseUrl: 'https://example.com',
                ),
            ),
            clock: new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
            logger: new NullLogger(),
        );
    }

    #[DataProvider('permitTypes')]
    public function testGenerationUsesPortsAndAlwaysIssuesVersionTwo(bool $guest, string $expectedUrl): void
    {
        $store = $this->createMock(PermitRepositoryInterface::class);
        $store->expects($this->once())->method('save')->with(
            self::callback(static fn(Permit $permit): bool => 'DEMO-1' === $permit->id && 'AB1234' === $permit->plate
                && $guest === $permit->guest
                && '48' === $permit->apartment && PermitSignatureVersion::HmacSha256 === $permit->signatureVersion),
            new \DateTimeImmutable('2026-11-03T23:59:59+02:00'),
            new \DateTimeImmutable('2026-11-03T12:00:00+02:00'),
        )->willReturn(true);
        $qr = $this->createMock(QrCodeGeneratorInterface::class);
        $qr->expects($this->once())->method('dataUri')->with($expectedUrl)->willReturn('data:local');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Permit generated', ['guest' => $guest]);
        $print = $this->generateHandler(
            $store,
            $qr,
            $logger,
        )->handle(new GeneratePermitCommand(permit: new Permit(
            id: 'DEMO-1',
            term: '03.11.2026',
            plate: 'ab1234',
            guest: $guest,
            apartment: '48',
        ), actor: 'admin'));
        self::assertSame('AB1234', $print->permit->plate);
        self::assertSame('03.11.2026', $print->permit->term);
        self::assertSame($expectedUrl, $print->url);
        self::assertSame('data:local', $print->image);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function permitTypes(): iterable
    {
        yield 'resident' => [
            false,
            'https://example.com/p/check?id=DEMO-1&plate=AB1234&term=03.11.2026&v=2&sign='
                . 'faa9475144b47737c7a975d198733d1662ff338bd53ae4a7cd5b5bb7ee215a6d',
        ];
        yield 'guest' => [
            true,
            'https://example.com/p/check?guest=1&id=DEMO-1&apt=48&term=03.11.2026&v=2&sign='
                . '7b9390f20fa4bcbce44259a7e11bfeaa87b7ae107358892b57e178957ba420d8',
        ];
    }

    public function testReprintLooksUpStoredDataWithoutSavingItAgain(): void
    {
        $permit = new Permit(id: 'DEMO-1', term: '03.11.2026', plate: 'AB1234', apartment: '48');
        $store = $this->createMock(PermitLookupInterface::class);
        $store->expects($this->once())->method('find')->with('fingerprint')->willReturn($permit);
        $qr = $this->createMock(QrCodeGeneratorInterface::class);
        $qr->expects($this->once())->method('dataUri')
            ->with('https://example.com/p/check?id=DEMO-1&plate=AB1234&term=03.11.2026&sign=a2a5b5e6c227c99a'
                . 'e055021bb9788069')
            ->willReturn('data:local');
        $print = $this->reprintHandler($store, $qr)->handle(new ReprintPermitQuery('fingerprint'));
        self::assertSame($permit, $print->permit);
        self::assertSame(
            'https://example.com/p/check?id=DEMO-1&plate=AB1234&term=03.11.2026&sign=a2a5b5e6c227c99ae055021bb9788069',
            $print->url,
        );
    }

    public function testMissingRecordCannotBeReprinted(): void
    {
        $store = self::createStub(PermitLookupInterface::class);
        $store->method('find')->willReturn(null);
        $qr = $this->createMock(QrCodeGeneratorInterface::class);
        $qr->expects($this->never())->method('dataUri');
        $this->expectException(PermitNotFoundException::class);
        $this->reprintHandler($store, $qr)->handle(new ReprintPermitQuery('missing'));
    }

    public function testVerificationUsesCurrentBlacklistAndClock(): void
    {
        $blacklist = $this->createMock(PermitBlacklistInterface::class);
        $blacklist->expects($this->once())->method('contains')->with('DEMO-1')->willReturn(false);
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $handler = new VerifyPermitHandler(
            new PermitVerifier($signer, $blacklist, new \DateTimeZone('Europe/Riga')),
            new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
        );
        self::assertSame(
            \App\Domain\Permit\Enum\PermitFailureReason::InvalidSignature,
            $handler->handle(new VerifyPermitQuery(
                new Permit(id: 'DEMO-1', term: '03.11.2026', plate: 'AB1234'),
                'invalid',
            )),
        );
    }
}
