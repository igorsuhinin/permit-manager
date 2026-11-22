<?php

declare(strict_types=1);

namespace Tests\Support;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

final class RecordingSentryTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];
    public bool $fail = false;

    #[\Override]
    public function send(Event $event): Result
    {
        if ($this->fail) {
            throw new \RuntimeException('private transport details');
        }
        $this->events[] = $event;

        return new Result(status: ResultStatus::success(), event: $event);
    }

    #[\Override]
    public function close(?int $timeout = null): Result
    {
        if ($this->fail) {
            throw new \RuntimeException('private transport details');
        }

        return new Result(ResultStatus::success());
    }
}
