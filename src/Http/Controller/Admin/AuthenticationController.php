<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Authentication\AuthenticationService;
use App\Http\Request\Validator\CsrfTokenValidator;
use App\Http\Response\AdminRenderer;
use App\Http\Session\AdminSessionAccessor;
use App\Http\Session\AdminSessionCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;

final readonly class AuthenticationController
{
    public function __construct(
        private AuthenticationService $authentication,
        private AdminSessionAccessor $sessions,
        private AdminSessionCookie $cookies,
        private CsrfTokenValidator $csrf,
        private AdminRenderer $renderer,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader(
            name: 'Location',
            value: $this->sessions->get($request)->authenticated ? '/admin/permits' : '/admin/login',
        )->withStatus(303);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->sessions->get($request);
        if ($session->authenticated) {
            return $response->withHeader(name: 'Location', value: '/admin/permits')->withStatus(303);
        }
        $error = null;
        $username = '';
        if ('POST' === $request->getMethod()) {
            $this->csrf->validate($request, $session);
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];
            /** @psalm-var mixed $username */
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            if (!is_string($username) || !is_string($password)) {
                throw new HttpBadRequestException($request);
            }
            /** @psalm-var mixed $address */
            $address = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
            $address = is_string($address) ? $address : 'unknown';
            $result = $this->authentication->login(
                session: $session,
                username: $username,
                password: $password,
                address: $address,
            );
            if ($result->retryAfter > 0) {
                $error = 'error.login_throttled';
                $response = $response->withStatus(429)->withHeader(
                    name: 'Retry-After',
                    value: (string) $result->retryAfter,
                );
            } elseif (null !== $result->session) {
                return $this->cookies->write(
                    response: $response->withStatus(303)->withHeader(name: 'Location', value: '/admin/permits'),
                    session: $result->session,
                    secure: 'https' === $request->getUri()->getScheme(),
                );
            } else {
                $error = 'error.login_failed';
                $response = $response->withStatus(401);
            }
        }

        return $this->renderer->render(
            request: $request,
            response: $response,
            template: 'admin/login',
            data: ['csrf' => $session->csrfToken, 'error' => $error, 'username' => $username],
        );
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->sessions->get($request);
        $this->csrf->validate($request, $session);
        $this->authentication->logout($session);

        return $this->cookies->write(
            response: $response->withStatus(303)->withHeader(name: 'Location', value: '/admin/login'),
            session: null,
            secure: 'https' === $request->getUri()->getScheme(),
        );
    }
}
