<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Authentication\AdminCredentials;
use App\Application\Command\GeneratePermitCommand;
use App\Application\Command\GeneratePermitHandler;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Http\Request\PermitInput;
use App\Http\Request\Validator\CsrfTokenValidator;
use App\Http\Response\AdminNotice;
use App\Http\Response\AdminRenderer;
use App\Http\Session\AdminSessionAccessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final readonly class PermitGenerationController
{
    public function __construct(
        private GeneratePermitHandler $generatePermit,
        private PermitInput $input,
        private AdminCredentials $credentials,
        private AdminSessionAccessor $sessions,
        private CsrfTokenValidator $csrf,
        private AdminRenderer $renderer,
        private LoggerInterface $logger,
    ) {}

    public function generate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->sessions->get($request);
        $data = [];
        $error = null;
        if ('POST' === $request->getMethod()) {
            $this->csrf->validate($request, $session);
            $body = $request->getParsedBody();
            $data = is_array($body) ? $body : [];
            try {
                $permit = $this->input->permit($data, generation: true);

                return $this->renderer->permit(
                    request: $request,
                    response: $response,
                    print: $this->generatePermit->handle(new GeneratePermitCommand(
                        permit: $permit,
                        actor: $this->credentials->username,
                    )),
                    notice: AdminNotice::PermitGenerated,
                );
            } catch (InvalidPermitInputException $exception) {
                $this->logger->notice('Invalid permit request', ['reason' => $exception->reason->value]);
                $error = 'error.' . $exception->reason->value;
                $response = $response->withStatus(422);
            }
        }

        return $this->renderer->render(
            request: $request,
            response: $response,
            template: 'admin/generate',
            data: [
                'csrf' => $session->csrfToken,
                'error' => $error, 'data' => array_filter($data, is_string(...)),
            ],
        );
    }
}
