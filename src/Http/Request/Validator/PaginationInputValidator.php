<?php

declare(strict_types=1);

namespace App\Http\Request\Validator;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;

final readonly class PaginationInputValidator
{
    /** @return array{string, int} */
    public function validate(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $search = $query['q'] ?? '';
        $pageValue = $query['page'] ?? '1';
        if (!is_string($search) || !is_string($pageValue) || strlen($search) > 200) {
            throw new HttpBadRequestException($request);
        }
        $page = filter_var($pageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if (false === $page) {
            throw new HttpBadRequestException($request);
        }

        return [$search, $page];
    }
}
