<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Enum\PermitCheckOutcome;
use App\Application\Query\VerifyPermitHandler;
use App\Application\Query\VerifyPermitQuery;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Request\PermitCheckResult;
use App\Http\Request\PermitInput;
use App\Http\Response\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class PermitController
{
    public function __construct(
        private VerifyPermitHandler $verifyPermit,
        private PermitInput $input,
        private Renderer $renderer,
    ) {}

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $check = $request->getAttribute(PermitCheckResult::class);
        if (!$check instanceof PermitCheckResult) {
            throw new \LogicException('Permit check middleware is missing.');
        }
        $view = ['cspNonce' => $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE)];
        try {
            $data = $request->getQueryParams();
            $permit = $this->input->permit($data);
            $reason = $this->verifyPermit->handle(new VerifyPermitQuery($permit, $this->input->text($data, 'sign')));
            $html = null === $reason
                ? $this->renderer->render('valid', $view + ['permit' => $permit])
                : $this->renderer->render('invalid', $view + ['reason' => $reason]);
            $outcome = null === $reason ? PermitCheckOutcome::Valid : PermitCheckOutcome::Rejected;
        } catch (InvalidPermitInputException $exception) {
            $reason = $exception->reason;
            $html = $this->renderer->render('invalid', $view + ['reason' => $reason]);
            $outcome = PermitCheckOutcome::Malformed;
        }
        $response = $this->html($response, $html);
        $check->complete($outcome, $reason);

        return $response;
    }

    private function html(ResponseInterface $response, string $html): ResponseInterface
    {
        $response->getBody()->write($html);

        return $response->withHeader(name: 'Content-Type', value: 'text/html; charset=utf-8');
    }
}
