<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use FAPost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
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
 * implementation, so they cannot drift in behaviour, only in transport.
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

        $this->container->call([$incoming, 'handle']);
    }
}
