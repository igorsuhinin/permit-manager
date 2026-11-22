<?php

declare(strict_types=1);

namespace App\Http\Request\Validator;

use App\Application\Authentication\AdminSession;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;

final readonly class CsrfTokenValidator
{
    public function __construct(private LoggerInterface $logger) {}

    public function validate(ServerRequestInterface $request, AdminSession $session): void
    {
        $data = $request->getParsedBody();
        $token = is_array($data) ? ($data['csrf'] ?? null) : null;
        if (!is_string($token) || !hash_equals(known_string: $session->csrfToken, user_string: $token)) {
            $this->logger->warning('Admin CSRF validation failed');
            throw new HttpForbiddenException($request);
        }
    }
}
