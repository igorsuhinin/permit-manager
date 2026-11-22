<?php

declare(strict_types=1);

use App\Application\Configuration\Settings;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Response\ErrorHandler;
use App\Infrastructure\Logging\CorrelatedLogger;
use App\Infrastructure\Logging\RequestLogContext;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

return static function (
    ?Settings $settings = null,
    ?LoggerInterface $logger = null,
    ?Connection $connection = null,
    ?string $requestId = null,
): App {
    $builder = new ContainerBuilder();
    $builder->addDefinitions(__DIR__ . '/../config/container.php');
    $builder->addDefinitions([RequestLogContext::class => new RequestLogContext($requestId)]);
    if (null !== $settings) {
        $builder->addDefinitions([Settings::class => $settings]);
    }
    if (null !== $connection) {
        $builder->addDefinitions([Connection::class => $connection]);
    }
    if (null !== $logger) {
        $builder->addDefinitions([LoggerInterface::class => $logger]);
    }
    $container = $builder->build();
    /** @var CorrelatedLogger $logger */
    $logger = $container->get(CorrelatedLogger::class);
    $container->set(LoggerInterface::class, $logger);
    /** @var ResponseFactoryInterface $responses */
    $responses = $container->get(ResponseFactoryInterface::class);
    $app = AppFactory::create(responseFactory: $responses, container: $container);

    /** @var Closure(App<DI\Container>): void $registerRoutes */
    $registerRoutes = require __DIR__ . '/../config/routes.php';
    $registerRoutes($app);
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    $errors = $app->addErrorMiddleware(
        displayErrorDetails: false,
        logErrors: true,
        logErrorDetails: true,
        logger: $logger,
    );
    $errors->setDefaultErrorHandler(ErrorHandler::class);
    $app->add(SecurityHeadersMiddleware::class);
    $app->add(RequestIdMiddleware::class);

    return $app;
};
