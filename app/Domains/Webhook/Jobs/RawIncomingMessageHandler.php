<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use Fapost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Pipeline\Pipeline;
use ValueError;

/**
 * Entry point for ingress jobs queued by a runtime that cannot speak PHP.
 *
 * Laravel dispatches jobs as PHP-serialized objects, a format no external process
 * can produce without depending on the internals of a trait. Its older
 * "Class@method" payload form takes plain JSON in `data` instead, which is what
 * the gateway writes — so this class exists to receive that JSON and hand it to
 * the very same {@see IncomingMessageJob} the PHP controller dispatches.
 *
 * Nothing about message handling lives here. Both ingress paths converge on one
 * implementation, so they cannot drift in behaviour, only in transport. The one thing done
 * here is running the job's middleware (the tenant access gate), because this path never
 * goes through the queue's own middleware pipeline.
 */
final readonly class RawIncomingMessageHandler
{
    public function __construct(
        private Container $container,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValueError When the payload version is not recognized.
     */
    public function handle(Job $job, array $data): void
    {
        $incoming = new IncomingMessageJob(InboundWebhookPayload::fromArray($data));

        // Binding the queue job restores the retry surface IncomingMessageJob relies
        // on: without it, release() and attempts() would have no job to act upon and
        // engine lock contention would drop the message instead of backing off.
        $incoming->setJob($job);

        // The gateway's "Class@method" payload never reaches CallQueuedHandler, which is what runs a
        // job's middleware, so the job's own middleware (the tenant access gate) is applied here.
        new Pipeline($this->container)
            ->send($incoming)
            ->through($incoming->middleware())
            ->then(function (IncomingMessageJob $incoming): void {
                $this->container->call([$incoming, 'handle']);
            });

        // A "Class@method" job is never deleted for us (CallQueuedHandler does that for ordinary
        // jobs): left alone it stays reserved and runs again after retry_after, answering the same
        // message up to maxTries times. End it unless the handler released it to retry later.
        if (! $job->isDeletedOrReleased()) {
            $job->delete();
        }
    }
}
