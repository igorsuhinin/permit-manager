<?php

declare(strict_types=1);

use App\Application\Port\DeploymentDiagnosticsQueriesInterface;
use App\Application\Port\SettingsProviderInterface;
use App\Infrastructure\Diagnostics\ConfigurationDiagnostics;
use App\Infrastructure\Diagnostics\DatabaseDiagnostics;
use App\Infrastructure\Diagnostics\DeploymentDiagnostics;
use DI\Container;
use function DI\get;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Twig\Environment;

return [
    DeploymentDiagnosticsQueriesInterface::class => get(DeploymentDiagnostics::class),
    DatabaseDiagnostics::class => static fn(
        Connection $connection,
        SettingsProviderInterface $settings,
    ): DatabaseDiagnostics => new DatabaseDiagnostics($connection, $settings, __DIR__ . '/../migrations'),
    ConfigurationDiagnostics::class => static function (Container $container): ConfigurationDiagnostics {
        $root = dirname(__DIR__);

        return new ConfigurationDiagnostics(
            rootDirectory: $root,
            templates: static function () use ($container): Environment {
                /** @var Environment $templates */
                $templates = $container->get(Environment::class);

                return $templates;
            },
            publicUi: static fn(): mixed => require __DIR__ . '/public-ui.php',
            maintenance: static function (): array {
                /** @var array{lock_file: string} $config */
                $config = require __DIR__ . '/maintenance.php';

                return $config;
            },
            logger: static function () use ($container): LoggerInterface {
                /** @var LoggerInterface $logger */
                $logger = $container->get(LoggerInterface::class);

                return $logger;
            },
        );
    },
];
