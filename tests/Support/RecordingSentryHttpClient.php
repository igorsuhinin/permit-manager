<?php

declare(strict_types=1);

namespace Tests\Support;

use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\HttpClient\Response;
use Sentry\Options;

final class RecordingSentryHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    public array $payloads = [];

    #[\Override]
    public function sendRequest(Request $request, Options $options): Response
    {
        $body = $request->getStringBody();
        if (null === $body) {
            throw new \RuntimeException('Expected a serialized Sentry envelope.');
        }
        $this->payloads[] = $body;

        return new Response(statusCode: 200, headers: [], error: '');
    }
}
