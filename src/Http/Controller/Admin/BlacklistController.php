<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Authentication\AdminCredentials;
use App\Application\Command\AddBlacklistedIdCommand;
use App\Application\Command\AddBlacklistedIdHandler;
use App\Application\Command\RemoveBlacklistedIdCommand;
use App\Application\Command\RemoveBlacklistedIdHandler;
use App\Application\Exception\DuplicateBlacklistEntryException;
use App\Application\Query\ListBlacklistHandler;
use App\Application\Query\ListBlacklistQuery;
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

final readonly class BlacklistController
{
    public function __construct(
        private AddBlacklistedIdHandler $addBlacklistedId,
        private RemoveBlacklistedIdHandler $removeBlacklistedId,
        private ListBlacklistHandler $listBlacklist,
        private AdminCredentials $credentials,
        private AdminSessionAccessor $sessions,
        private CsrfTokenValidator $csrf,
        private PaginationInputValidator $pagination,
        private AdminRenderer $renderer,
        private AdminNoticeCookie $notices,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->sessions->get($request);
        $error = null;
        $id = '';
        $reason = '';
        if ('POST' === $request->getMethod()) {
            $this->csrf->validate($request, $session);
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];
            /** @psalm-var mixed $id */
            $id = $data['id'] ?? '';
            /** @psalm-var mixed $reason */
            $reason = $data['reason'] ?? '';
            $action = $data['action'] ?? '';
            if (!is_string($id) || !is_string($reason) || !in_array($action, ['add', 'remove'], true)) {
                throw new HttpBadRequestException($request);
            }
            try {
                if ('add' === $action) {
                    $this->addBlacklistedId->handle(new AddBlacklistedIdCommand(
                        id: $id,
                        reason: $reason,
                        actor: $this->credentials->username,
                    ));
                } else {
                    $this->removeBlacklistedId->handle(new RemoveBlacklistedIdCommand(
                        id: $id,
                        reason: $reason,
                        actor: $this->credentials->username,
                    ));
                }

                return $this->notices->write(
                    $request,
                    $response->withHeader(name: 'Location', value: '/admin/blacklist')->withStatus(303),
                    'add' === $action ? AdminNotice::BlacklistAdded : AdminNotice::BlacklistRemoved,
                );
            } catch (InvalidBlacklistEntryException) {
                $error = 'blacklist.invalid';
                $response = $response->withStatus(422);
            } catch (DuplicateBlacklistEntryException) {
                $error = 'blacklist.duplicate';
                $response = $response->withStatus(409);
            }
        }
        [$search, $page] = $this->pagination->validate($request);
        $result = $this->listBlacklist->handle(new ListBlacklistQuery($search, $page));

        return $this->renderer->render(
            request: $request,
            response: $response,
            template: 'admin/blacklist',
            data: [
                'csrf' => $session->csrfToken,
                'entries' => $result->items, 'q' => $search, 'page' => $page, 'hasNext' => $result->hasNext,
                'id' => $id, 'reason' => $reason, 'error' => $error,
                'previousUrl' => '/admin/blacklist?' . http_build_query(['q' => $search, 'page' => $page - 1]),
                'nextUrl' => '/admin/blacklist?' . http_build_query(['q' => $search, 'page' => $page + 1]),
            ],
        );
    }
}
