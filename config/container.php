<?php

declare(strict_types=1);

use App\Application\Authentication\AdminCredentials;
use App\Application\Command\PrunePermitChecksHandler;
use App\Application\Configuration\Settings;
use App\Application\Port\AdminAuditTrailInterface;
use App\Application\Port\AdminSessionRepositoryInterface;
use App\Application\Port\BlacklistManagementInterface;
use App\Application\Port\BlacklistQueriesInterface;
use App\Application\Port\InitialSettingsStoreInterface;
use App\Application\Port\LogContextInterface;
use App\Application\Port\LoginAttemptStoreInterface;
use App\Application\Port\MaintenanceLockInterface;
use App\Application\Port\PermitCheckPrunerInterface;
use App\Application\Port\PermitCheckQueriesInterface;
use App\Application\Port\PermitCheckRecorderInterface;
use App\Application\Port\PermitLookupInterface;
use App\Application\Port\PermitQueriesInterface;
use App\Application\Port\QrCodeGeneratorInterface;
use App\Application\Port\SettingsProviderInterface;
use App\Application\Port\TransactionInterface;
use App\Console\Command\PruneChecksCommand;
use App\Domain\Permit\Port\PermitBlacklistInterface;
use App\Domain\Permit\Port\PermitRepositoryInterface;
use App\Domain\Permit\Service\PermitSigner;
use App\Domain\Permit\Service\PermitVerifier;
use App\Domain\Permit\Validator\PermitIdValidator;
use App\Domain\Permit\Validator\PermitIssuanceValidator;
use App\Http\Translation\AdminTranslationExtension;
use App\Http\Translation\PublicUi;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Configuration\InitialSettingsFile;
use App\Infrastructure\Logging\RequestLogContext;
use App\Infrastructure\Maintenance\FileMaintenanceLock;
use App\Infrastructure\Persistence\AdminAuditTrail;
use App\Infrastructure\Persistence\AdminSessionRepository;
use App\Infrastructure\Persistence\BlacklistRepository;
use App\Infrastructure\Persistence\DatabaseTransaction;
use App\Infrastructure\Persistence\LoginAttemptStore;
use App\Infrastructure\Persistence\PermitCheckPruner;
use App\Infrastructure\Persistence\PermitCheckRepository;
use App\Infrastructure\Persistence\PermitRepository;
use App\Infrastructure\Persistence\SettingsRepository;
use App\Infrastructure\QrCode\QrCodeGenerator;
use function DI\get;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** @var array<string, mixed> $diagnostics */
$diagnostics = require __DIR__ . '/diagnostics.php';

return [
    ...$diagnostics,
    LogContextInterface::class => get(RequestLogContext::class),
    AdminAuditTrailInterface::class => get(AdminAuditTrail::class),
    TransactionInterface::class => get(DatabaseTransaction::class),
    PermitCheckPrunerInterface::class => get(PermitCheckPruner::class),
    MaintenanceLockInterface::class => static function (): FileMaintenanceLock {
        /** @var array{lock_file: string} $config */
        $config = require __DIR__ . '/maintenance.php';

        return new FileMaintenanceLock($config['lock_file']);
    },
    PruneChecksCommand::class => static function (
        PrunePermitChecksHandler $handler,
        LoggerInterface $logger,
    ): PruneChecksCommand {
        /** @var array{retention_days: int, batch_size: int, max_batches: int, lock_file: string} $config */
        $config = require __DIR__ . '/maintenance.php';

        return new PruneChecksCommand($handler, $config, $logger);
    },
    PermitRepositoryInterface::class => get(PermitRepository::class),
    PermitLookupInterface::class => get(PermitRepository::class),
    SettingsProviderInterface::class => get(SettingsRepository::class),
    QrCodeGeneratorInterface::class => get(QrCodeGenerator::class),
    AdminSessionRepositoryInterface::class => get(AdminSessionRepository::class),
    InitialSettingsStoreInterface::class => get(InitialSettingsFile::class),
    InitialSettingsFile::class => static function (): InitialSettingsFile {
        /** @var string $path */
        $path = require __DIR__ . '/initial-settings-path.php';

        return new InitialSettingsFile($path);
    },
    BlacklistManagementInterface::class => get(BlacklistRepository::class),
    BlacklistQueriesInterface::class => get(BlacklistRepository::class),
    PermitQueriesInterface::class => get(PermitRepository::class),
    PermitCheckQueriesInterface::class => get(PermitCheckRepository::class),
    PermitCheckRecorderInterface::class => get(PermitCheckRepository::class),
    LoginAttemptStoreInterface::class => get(LoginAttemptStore::class),
    Connection::class => static function (): Connection {
        /** @var Connection $connection */
        $connection = require __DIR__ . '/database.php';

        return $connection;
    },
    AdminCredentials::class => static fn(
        Settings $settings,
        SettingsProviderInterface $repository,
    ): AdminCredentials => new AdminCredentials(
        username: $repository->adminUsername(),
        passwordHash: $settings->passwordHash,
    ),
    Settings::class => static fn(SettingsProviderInterface $settings): Settings => $settings->load(),
    LoggerInterface::class => static function (): LoggerInterface {
        /** @var LoggerInterface $logger */
        $logger = require __DIR__ . '/logger.php';

        return $logger;
    },
    ClockInterface::class => get(SystemClock::class),
    ResponseFactoryInterface::class => get(ResponseFactory::class),
    PermitSigner::class => static fn(Settings $settings): PermitSigner => new PermitSigner(
        legacySecret: $settings->secret,
        hmacKey: $settings->hmacKey,
    ),
    PermitBlacklistInterface::class => get(BlacklistRepository::class),
    PermitIssuanceValidator::class => static fn(
        PermitIdValidator $ids,
        Settings $settings,
    ): PermitIssuanceValidator => new PermitIssuanceValidator(
        $ids,
        new DateTimeZone($settings->timezone),
    ),
    PermitVerifier::class => static fn(
        PermitSigner $signer,
        PermitBlacklistInterface $blacklist,
        Settings $settings,
    ): PermitVerifier => new PermitVerifier(
        $signer,
        $blacklist,
        new DateTimeZone($settings->timezone),
    ),
    TranslatorInterface::class => static function (): TranslatorInterface {
        /** @var TranslatorInterface $translator */
        $translator = require __DIR__ . '/translations.php';

        return $translator;
    },
    PublicUi::class => static function (TranslatorInterface $translator): PublicUi {
        /** @var array{messages: array<string, string>, parameters: array<string, string>} $config */
        $config = require __DIR__ . '/public-ui.php';

        return new PublicUi($translator, $config['parameters']);
    },
    Environment::class => static function (AdminTranslationExtension $extension, PublicUi $publicUi): Environment {
        /** @var Environment $twig */
        $twig = require __DIR__ . '/templates.php';
        $twig->addExtension($extension);
        $twig->addExtension($publicUi);

        return $twig;
    },
];
