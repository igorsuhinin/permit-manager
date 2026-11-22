<?php

declare(strict_types=1);

use App\Console\Command\DoctorCommand;
use App\Console\Command\PruneChecksCommand;
use App\Console\Command\SetupCommand;
use App\Infrastructure\Logging\SentryIntegration;
use DI\ContainerBuilder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;

$status = 1;
try {
    require __DIR__ . '/../vendor/autoload.php';
    $builder = new ContainerBuilder();
    $builder->addDefinitions(__DIR__ . '/../config/container.php');
    $container = $builder->build();
    $console = new Application('Permit Manager');
    $console->setAutoExit(false);
    $console->setCatchExceptions(false);
    $console->setCommandLoader(new FactoryCommandLoader([
        'app:setup' => static function () use ($container): Command {
            /** @var SetupCommand $command */
            $command = $container->get(SetupCommand::class);

            return new Command(code: $command);
        },
        'app:doctor' => static function () use ($container): Command {
            /** @var DoctorCommand $command */
            $command = $container->get(DoctorCommand::class);

            return new Command(code: $command);
        },
        'checks:prune' => static function () use ($container): Command {
            /** @var PruneChecksCommand $command */
            $command = $container->get(PruneChecksCommand::class);

            return new Command(code: $command);
        },
    ]));
    $status = $console->run();
} catch (Throwable $exception) {
    error_log('Application CLI startup failed: ' . $exception::class);
    fwrite(stream: STDERR, data: "Unable to start application CLI. Check private configuration and logs.\n");
} finally {
    if (class_exists(SentryIntegration::class, false)) {
        SentryIntegration::flush();
    }
}
exit($status);
