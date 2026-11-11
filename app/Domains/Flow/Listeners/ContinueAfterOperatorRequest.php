<?php

declare(strict_types=1);

namespace App\Domains\Flow\Listeners;

use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Jobs\Flow\ContinueAfterOperatorRequestJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Flow's side of the Conversation → Flow seam: an operator request changed state, so the contact is told and the
 * waiting session moves on — in a job on `flow.execution`, which takes the session lock.
 *
 * Runs inside whatever changed the request (an operator's Accept, the routing pipeline, the timeout job), after the
 * change is committed: a failure to queue the job is reported, never thrown into that caller, whose change has already
 * happened. The session is not lost: the next message from the contact wakes it, and the node reads the outcome from
 * the request.
 */
final readonly class ContinueAfterOperatorRequest
{
    public function __construct(
        private Dispatcher $bus,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function handle(OperatorRequestStateChanged $event): void
    {
        try {
            $this->bus->dispatch(new ContinueAfterOperatorRequestJob($event->tenantId, $event->requestId, $event->state));
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
