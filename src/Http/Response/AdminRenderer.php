<?php

declare(strict_types=1);

namespace App\Http\Response;

use App\Application\Authentication\AuthenticationService;
use App\Application\View\PrintablePermit;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Session\AdminNoticeCookie;
use App\Http\Translation\AdminLanguage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminRenderer
{
    public function __construct(
        private Renderer $renderer,
        private AuthenticationService $authentication,
        private AdminLanguage $language,
        private AdminNoticeCookie $notices,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data,
    ): ResponseInterface {
        $response->getBody()->write($this->renderer->render($template, $data + [
            'username' => $this->authentication->username(),
            'locale' => $this->language->resolve($request),
            'languageLinks' => $this->language->links($request),
            'notice' => $this->notices->read($request),
        ]));

        if (array_key_exists(AdminNoticeCookie::COOKIE, $request->getCookieParams())) {
            $response = $this->notices->clear($request, $response);
        }

        return $response->withHeader(name: 'Content-Type', value: 'text/html; charset=utf-8');
    }

    public function permit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        PrintablePermit $print,
        ?AdminNotice $notice = null,
        bool $autoPrint = false,
    ): ResponseInterface {
        return $this->render(
            request: $request,
            response: $response,
            template: 'permit',
            data: [
                'permit' => $print->permit, 'qrtext' => $print->url, 'qrimage' => $print->image,
                'cspNonce' => $request->getAttribute(SecurityHeadersMiddleware::NONCE_ATTRIBUTE),
                'notice' => $notice,
                'autoPrint' => $autoPrint,
            ],
        );
    }
}
