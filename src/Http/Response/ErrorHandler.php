<?php

declare(strict_types=1);

namespace App\Http\Response;

use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Translation\AdminLanguage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ErrorHandler
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private AdminLanguage $language,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        if ($logErrors) {
            $context = [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status' => $status,
            ];
            if ($status >= 500) {
                $context['exception'] = $exception;
            }
            try {
                $this->logger->log(
                    level: $status >= 500 ? LogLevel::ERROR : LogLevel::NOTICE,
                    message: 'HTTP request failed',
                    context: $context,
                );
            } catch (\Throwable) {
                // A full disk or unavailable log destination must not expose details.
                /** @psalm-var mixed $id */
                $id = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);
                $correlation = is_string($id) && 1 === preg_match(pattern: '/\A[a-f0-9]{32}\z/', subject: $id)
                    ? 'request_id=' . $id . '; ' : '';
                error_log('Application logger failed; ' . $correlation . $exception::class . ': '
                    . $exception->getMessage()
                    . ' in ' . $exception->getFile() . ':' . $exception->getLine());
            }
        }

        $response = $this->responses->createResponse($status);
        if ($exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader(name: 'Allow', value: implode(', ', $exception->getAllowedMethods()));
        }
        $locale = 'en';
        $message = $response->getReasonPhrase();
        $link = '/';
        $label = 'Home';
        $path = $request->getUri()->getPath();
        if ('/admin' === $path || str_starts_with(haystack: $path, needle: '/admin/')) {
            $locale = $this->language->resolve($request);
            $key = in_array($status, [400, 403, 404, 405, 500], true) ? 'error.http' . $status : 'error.http';
            $message = $this->translator->trans(id: $key, parameters: [], domain: 'admin', locale: $locale);
            $label = $this->translator->trans(id: 'sign_in', parameters: [], domain: 'admin', locale: $locale);
            $link = '/admin';
            $response = $response->withHeader(name: 'Content-Language', value: $locale);
        }
        if ('HEAD' !== $request->getMethod()) {
            $title = htmlspecialchars(string: $status . ' ' . $message, flags: ENT_QUOTES, encoding: 'UTF-8');
            $label = htmlspecialchars(string: $label, flags: ENT_QUOTES, encoding: 'UTF-8');
            $response->getBody()->write('<!doctype html><html lang="' . $locale . '"><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . $title . '</title><h1>' . $title . '</h1><a href="' . $link . '">' . $label
                    . '</a></html>');
        }

        return $response->withHeader(name: 'Content-Type', value: 'text/html; charset=utf-8')
            ->withHeader(name: 'Cache-Control', value: 'no-store');
    }
}
