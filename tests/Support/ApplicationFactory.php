<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Configuration\Settings;
use DI\Container;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Slim\App;

final class ApplicationFactory
{
    /** @return App<Container> */
    public static function create(
        ?Settings $settings = null,
        ?LoggerInterface $logger = null,
        ?Connection $connection = null,
        ?string $requestId = null,
    ): App {
        /**
         * @var \Closure(
         *     ?Settings $settings,
         *     ?LoggerInterface $logger,
         *     ?Connection $connection,
         *     ?string $requestId
         * ): App<Container> $factory
         */
        $factory = require __DIR__ . '/../../bootstrap/app.php';

        return $factory(settings: $settings, logger: $logger, connection: $connection, requestId: $requestId);
    }
}
