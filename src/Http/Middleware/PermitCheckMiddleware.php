<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Command\RecordPermitCheckCommand;
use App\Application\Command\RecordPermitCheckHandler;
use App\Http\Request\PermitCheckResult;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PermitCheckMiddleware implements MiddlewareInterface
{
    public function __construct(private RecordPermitCheckHandler $checks, private ClockInterface $clock) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $check = new PermitCheckResult();
        $checkedAt = $this->clock->now();
        $status = 500;
        try {
            $response = $handler->handle($request->withAttribute(PermitCheckResult::class, $check));
            $status = $response->getStatusCode();

            return $response;
        } finally {
            /** @psalm-var mixed $id */
            $id = $request->getQueryParams()['id'] ?? null;
            /** @psalm-var mixed $term */
            $term = $request->getQueryParams()['term'] ?? null;
            /** @psalm-var mixed $apartment */
            $apartment = $request->getQueryParams()['apt'] ?? null;
            $this->checks->handle(new RecordPermitCheckCommand(
                id: is_string($id) ? $id : null,
                method: $request->getMethod(),
                path: $request->getUri()->getPath(),
                outcome: $check->outcome,
                reason: $check->reason,
                status: $status,
                checkedAt: $checkedAt,
                term: is_string($term) ? $term : null,
                apartment: is_string($apartment) ? $apartment : null,
            ));
        }
    }
}
