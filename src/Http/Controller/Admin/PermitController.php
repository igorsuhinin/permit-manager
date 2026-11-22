<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Configuration\Settings;
use App\Application\Exception\PermitNotFoundException;
use App\Application\Query\ListPermitsHandler;
use App\Application\Query\ListPermitsQuery;
use App\Application\Query\ReprintPermitHandler;
use App\Application\Query\ReprintPermitQuery;
use App\Domain\Permit\Exception\InvalidPermitInputException;
use App\Http\Request\Validator\CsrfTokenValidator;
use App\Http\Request\Validator\PaginationInputValidator;
use App\Http\Response\AdminNotice;
use App\Http\Response\AdminRenderer;
use App\Http\Session\AdminSessionAccessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final readonly class PermitController
{
    public function __construct(
        private ListPermitsHandler $listPermits,
        private ReprintPermitHandler $reprintPermit,
        private AdminSessionAccessor $sessions,
        private CsrfTokenValidator $csrf,
        private PaginationInputValidator $pagination,
        private AdminRenderer $renderer,
        private Settings $settings,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->sessions->get($request);
        [$search, $page] = $this->pagination->validate($request);
        $result = $this->listPermits->handle(new ListPermitsQuery($search, $page));

        return $this->renderer->render(
            request: $request,
            response: $response,
            template: 'admin/permits',
            data: [
                'csrf' => $session->csrfToken,
                'permits' => $result->items, 'q' => $search, 'page' => $page, 'hasNext' => $result->hasNext,
                'previousUrl' => '/admin/permits?' . http_build_query(['q' => $search, 'page' => $page - 1]),
                'nextUrl' => '/admin/permits?' . http_build_query(['q' => $search, 'page' => $page + 1]),
                'timezone' => $this->settings->timezone,
            ],
        );
    }

    /** @param array{fingerprint: string} $args */
    public function reprint(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $session = $this->sessions->get($request);
        $this->csrf->validate($request, $session);
        try {
            return $this->renderer->permit(
                request: $request,
                response: $response,
                print: $this->reprintPermit->handle(new ReprintPermitQuery($args['fingerprint'])),
                notice: AdminNotice::PermitReprinted,
                autoPrint: true,
            );
        } catch (PermitNotFoundException) {
            throw new HttpNotFoundException($request);
        } catch (InvalidPermitInputException) {
            throw new HttpForbiddenException($request);
        }
    }
}
