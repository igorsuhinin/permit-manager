<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Audit\AdminAudit;
use App\Application\Command\GeneratePermitCommand;
use App\Application\Command\GeneratePermitHandler;
use App\Application\Configuration\Settings;
use App\Application\Support\PrintablePermitFactory;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Domain\Permit\Model\Permit;
use App\Domain\Permit\Service\PermitSigner;
use App\Domain\Permit\Validator\PermitIdValidator;
use App\Domain\Permit\Validator\PermitIssuanceValidator;
use App\Infrastructure\Persistence\AdminAuditTrail;
use App\Infrastructure\Persistence\DatabaseTransaction;
use App\Infrastructure\Persistence\PermitRepository;
use App\Infrastructure\QrCode\QrCodeGenerator;
use Endroid\QrCode\Writer\SvgWriter;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\Database;
use Tests\Support\FrozenClock;

#[Medium]
#[CoversNamespace('App')]
#[UsesNamespace('Database\\Migrations')]
final class GeneratePermitHandlerTest extends TestCase
{
    private function handler(): GeneratePermitHandler
    {
        $signer = new PermitSigner(legacySecret: 'test-secret', hmacKey: str_repeat('h', 32));
        $connection = Database::create(false);

        $settings = new Settings(
            secret: 'test-secret',
            hmacKey: str_repeat('h', 32),
            passwordHash: password_hash(password: 'password', algo: PASSWORD_BCRYPT, options: ['cost' => 4]),
            baseUrl: 'http://localhost:8080/',
        );

        return new GeneratePermitHandler(
            validator: new PermitIssuanceValidator(new PermitIdValidator(), new \DateTimeZone('Europe/Riga')),
            permits: new PermitRepository($connection),
            printing: new PrintablePermitFactory($signer, new QrCodeGenerator(new SvgWriter()), $settings),
            clock: new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
            audit: new AdminAudit(
                new AdminAuditTrail($connection),
                new FrozenClock(new \DateTimeImmutable('2026-11-03T12:00:00+02:00')),
            ),
            transactions: new DatabaseTransaction($connection),
            logger: new NullLogger(),
        );
    }

    #[DataProvider('incompletePermits')]
    public function testIncompletePermitsCannotBeGenerated(Permit $permit): void
    {
        $this->expectException(InvalidPermitInputException::class);
        $this->expectExceptionMessageIs('details');
        $this->handler()->handle(new GeneratePermitCommand(permit: $permit, actor: 'admin'));
    }

    /** @return iterable<string, array{Permit}> */
    public static function incompletePermits(): iterable
    {
        yield 'missing ID' => [new Permit(id: '', term: '31.12.2099', plate: 'AB1234')];
        yield 'missing resident plate' => [new Permit(id: '26/1', term: '31.12.2099')];
        yield 'missing guest apartment' => [new Permit(id: '26/1', term: '31.12.2099', guest: true)];
    }

    public function testConfiguredUrlWithTrailingSlashGeneratesUsableLink(): void
    {
        $url = $this->handler()->handle(new GeneratePermitCommand(permit: new Permit(
            id: '26/1',
            term: '31.12.2099',
            plate: 'AB1234',
        ), actor: 'admin'))->url;
        self::assertStringStartsWith('http://localhost:8080/p/check?', $url);
    }
}
