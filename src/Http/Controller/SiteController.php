<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Response\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SiteController
{
    public function __construct(private Renderer $renderer) {}

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->renderer->render('home'));

        return $response->withHeader(name: 'Content-Type', value: 'text/html; charset=utf-8');
    }

    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('{"status":"ok"}');

        return $response->withHeader(name: 'Content-Type', value: 'application/json');
    }

    public function permitRedirect(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader(name: 'Location', value: '/admin/permits/generate')->withStatus(303);
    }
}
