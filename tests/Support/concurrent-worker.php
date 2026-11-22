<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Authentication\AdminSessionService;
use App\Application\Authentication\AuthenticationService;
use App\Application\Command\AddBlacklistedIdCommand;
use App\Application\Command\AddBlacklistedIdHandler;
use App\Application\Command\GeneratePermitCommand;
use App\Application\Command\GeneratePermitHandler;
use App\Application\Command\RemoveBlacklistedIdCommand;
use App\Application\Command\RemoveBlacklistedIdHandler;
use App\Application\Exception\DuplicateBlacklistEntryException;
use App\Domain\Permit\Model\Permit;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

require __DIR__ . '/../../vendor/autoload.php';
try {
    $builder = new ContainerBuilder();
    $builder->addDefinitions(__DIR__ . '/../../config/container.php');
    $builder->addDefinitions([
        Connection::class => MySqlDatabase::connect(),
        ClockInterface::class => new FrozenClock(new \DateTimeImmutable('2026-11-11T12:00:00Z')),
        LoggerInterface::class => new NullLogger(),
    ]);
    $container = $builder->build();
    /** @var AdminSessionService $sessions */
    $sessions = $container->get(AdminSessionService::class);
    $operation = $argv[1] ?? '';
    $session = 'login' === $operation ? $sessions->open(null) : null;
    $container->get(match ($operation) {
        'issue' => GeneratePermitHandler::class,
        'add' => AddBlacklistedIdHandler::class,
        'remove' => RemoveBlacklistedIdHandler::class,
        'login' => AuthenticationService::class,
        default => throw new \RuntimeException('Unknown concurrent operation.'),
    });
    fwrite(stream: STDOUT, data: "READY\n");
    fflush(STDOUT);
    if ("GO\n" !== fgets(STDIN)) {
        throw new \RuntimeException('Worker barrier failed.');
    }
    $result = ['result' => 'ok'];
    switch ($operation) {
        case 'issue':
            /** @var GeneratePermitHandler $handler */
            $handler = $container->get(GeneratePermitHandler::class);
            $print = $handler->handle(new GeneratePermitCommand(
                permit: new Permit(id: 'DEMO-CONCURRENT', term: '31.12.2099', plate: 'TEST'),
                actor: 'admin',
            ));
            $result['url'] = $print->url;
            break;
        case 'add':
            /** @var AddBlacklistedIdHandler $handler */
            $handler = $container->get(AddBlacklistedIdHandler::class);
            $handler->handle(new AddBlacklistedIdCommand(id: 'DEMO-RACE', reason: 'test', actor: 'admin'));
            break;
        case 'remove':
            /** @var RemoveBlacklistedIdHandler $handler */
            $handler = $container->get(RemoveBlacklistedIdHandler::class);
            $handler->handle(new RemoveBlacklistedIdCommand(id: 'DEMO-RACE', reason: '', actor: 'admin'));
            break;
        case 'login':
            /** @var AuthenticationService $handler */
            $handler = $container->get(AuthenticationService::class);
            if (null === $session) {
                throw new \LogicException('Login fixture session missing.');
            }
            $login = $handler->login(session: $session, username: 'admin', password: 'incorrect', address: '192.0.2.1');
            $result['result'] = $login->retryAfter > 0 ? 'throttled' : 'failed';
            break;
    }
} catch (DuplicateBlacklistEntryException) {
    $result = ['result' => 'duplicate'];
} catch (\Throwable $exception) {
    $result = ['result' => 'error', 'error_type' => $exception::class];
}
echo json_encode(value: $result, flags: JSON_THROW_ON_ERROR) . "\n";
