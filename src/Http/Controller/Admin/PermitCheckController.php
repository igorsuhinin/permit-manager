<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Authentication\AdminCredentials;
use App\Application\Command\AddBlacklistedIdCommand;
use App\Application\Command\AddBlacklistedIdHandler;
use App\Application\Command\RemoveBlacklistedIdCommand;
use App\Application\Command\RemoveBlacklistedIdHandler;
use App\Application\Configuration\Settings;
use App\Application\Enum\PermitCheckOutcome;
use App\Application\Exception\DuplicateBlacklistEntryException;
use App\Application\Query\ListPermitChecksHandler;
use App\Application\Query\ListPermitChecksQuery;
use App\Domain\Permit\Exception\InvalidBlacklistEntryException;
use App\Http\Request\Validator\CsrfTokenValidator;
use App\Http\Request\Validator\PaginationInputValidator;
use App\Http\Response\AdminNotice;
use App\Http\Response\AdminRenderer;
use App\Http\Session\AdminNoticeCookie;
use App\Http\Session\AdminSessionAccessor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;

final readonly class PermitCheckController
{
    public function __construct(
        private ListPermitChecksHandler $listChecks,
        private AddBlacklistedIdHandler $addBlacklistedId,
        private RemoveBlacklistedIdHandler $removeBlacklistedId,
        private AdminCredentials $credentials,
        private AdminSessionAccessor $sessions,
        private CsrfTokenValidator $csrf,
        private PaginationInputValidator $pagination,
        private AdminRenderer $renderer,
        private AdminNoticeCookie $notices,
        private Settings $settings,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, $this->query($request));
    }

    public function blacklist(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->csrf->validate($request, $this->sessions->get($request));
        $query = $this->query($request);
        $data = $request->getParsedBody();
        /** @psalm-var mixed $id */
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        $action = is_array($data) ? ($data['action'] ?? 'add') : 'add';
        if (!is_string($id) || !in_array($action, ['add', 'remove'], true)) {
            throw new HttpBadRequestException($request);
        }
        try {
            if ('add' === $action) {
                $this->addBlacklistedId->handle(new AddBlacklistedIdCommand(
                    id: $id,
                    reason: '',
                    actor: $this->credentials->username,
                ));
            } else {
                $this->removeBlacklistedId->handle(new RemoveBlacklistedIdCommand(
                    id: $id,
                    reason: '',
                    actor: $this->credentials->username,
                ));
            }
        } catch (InvalidBlacklistEntryException) {
            return $this->render(
                request: $request,
                response: $response->withStatus(422),
                query: $query,
                error: 'blacklist.invalid',
            );
        } catch (DuplicateBlacklistEntryException) {
            return $this->render(
                request: $request,
                response: $response->withStatus(409),
                query: $query,
                error: 'blacklist.duplicate',
            );
        }

        return $this->notices->write(
            $request,
            $response->withStatus(303)->withHeader(name: 'Location', value: '/admin/checks?' . http_build_query([
                'q' => $query->search, 'result' => $query->outcome->value ?? '', 'page' => $query->page,
            ])),
            'add' === $action ? AdminNotice::BlacklistAdded : AdminNotice::BlacklistRemoved,
        );
    }

    private function query(ServerRequestInterface $request): ListPermitChecksQuery
    {
        [$search, $page] = $this->pagination->validate($request);
        $result = $request->getQueryParams()['result'] ?? '';
        if (!is_string($result)) {
            throw new HttpBadRequestException($request);
        }
        $outcome = '' === $result ? null : PermitCheckOutcome::tryFrom($result);
        if ('' !== $result && null === $outcome) {
            throw new HttpBadRequestException($request);
        }

        return new ListPermitChecksQuery($search, $outcome, $page);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ListPermitChecksQuery $query,
        ?string $error = null,
    ): ResponseInterface {
        $resultPage = $this->listChecks->handle($query);
        $search = $query->search;
        $result = $query->outcome->value ?? '';
        $page = $query->page;

        return $this->renderer->render(
            request: $request,
            response: $response,
            template: 'admin/checks',
            data: [
                'csrf' => $this->sessions->get($request)->csrfToken,
                'error' => $error,
                'blacklistUrl' => '/admin/checks/blacklist?' . http_build_query([
                    'q' => $search, 'result' => $result, 'page' => $page,
                ]),
                'outcomes' => PermitCheckOutcome::cases(),
                'checks' => $resultPage->items,
                'q' => $search,
                'result' => $result,
                'page' => $page,
                'hasNext' => $resultPage->hasNext,
                'previousUrl' => '/admin/checks?' . http_build_query([
                    'q' => $search,
                    'result' => $result,
                    'page' => $page - 1,
                ]),
                'nextUrl' => '/admin/checks?' . http_build_query([
                    'q' => $search,
                    'result' => $result,
                    'page' => $page + 1,
                ]),
                'timezone' => $this->settings->timezone,
            ],
        );
    }
}
