<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The start the jobs of a `start_flow` node and of an event trigger share, under the contact's session lock (outside
 * the routing pipeline, so it cannot race an inbound message, the asking conversation or a second job for the same
 * contact and assistant): the start key is looked up again, the contact must have a channel on an active channel of
 * the assistant and must not be busy ({@see ContactFlowStartCollision}), the stale live conversations are ended, and
 * the engine starts the conversation under the start key.
 *
 * The optional admission hook is asked once the contact proved free, after the busy verdict and before a conversation
 * is ended: a start skipped for a busy contact or a missing channel is never metered, and a refused start ends nothing.
 *
 * None of the outcomes is an exception: repeating the start would not change it. The job logs each under its own keys.
 * A unique `start_key` violation that finds the row is {@see ContactFlowStartStatus::RaceLost}: the duplicate started once.
 *
 * Not bound: built per job through the job's `handle()` injection, before the job switches to the tenant — a collaborator
 * that depends on the current tenant must not read it when it is built.
 */
final readonly class ContactFlowStarter
{
    public function __construct(
        private FlowExecutionGuardInterface $guard,
        private FlowSessionRepositoryInterface $sessions,
        private ContactChannelProbe $channels,
        private ContactFlowStartCollision $collision,
        private FlowSessionTerminator $terminator,
        private FlowEngineInterface $engine,
    ) {
    }

    /**
     * @param  (Closure(): bool)|null  $admission  whether run admission lets the start through; null for none
     */
    public function start(ContactFlowStartRequest $request, ?Closure $admission = null): ContactFlowStartOutcome
    {
        /** @var ContactFlowStartOutcome $outcome */
        $outcome = $this->guard->run(
            tenantId: $request->guardTenantId,
            contactId: (string) $request->contact->getKey(),
            assistantId: $request->assistantId,
            callback: fn (): ContactFlowStartOutcome => $this->startUnderLock($request, $admission),
        );

        return $outcome;
    }

    /**
     * @param  (Closure(): bool)|null  $admission
     */
    private function startUnderLock(ContactFlowStartRequest $request, ?Closure $admission): ContactFlowStartOutcome
    {
        $contact  = $request->contact;
        $startKey = $request->startKey;

        if (null !== $this->sessions->findByStartKey($startKey)) {
            return ContactFlowStartOutcome::of(ContactFlowStartStatus::Duplicate);
        }

        $thread = $this->channels->deliveryThread((string) $contact->tenant_id, (string) $contact->getKey(), $request->assistantId);

        if (null === $thread) {
            return ContactFlowStartOutcome::of(ContactFlowStartStatus::NoChannel);
        }

        $verdict = $this->collision->assess($thread, $request->busyWindowMinutes, $request->askingSessionId);

        if ($verdict->isBusy()) {
            return ContactFlowStartOutcome::busy($verdict->busyReason);
        }

        if (null !== $admission && ! $admission()) {
            return ContactFlowStartOutcome::of(ContactFlowStartStatus::NotAdmitted);
        }

        foreach ($verdict->stale as $stale) {
            $this->terminator->terminate($stale);
        }

        try {
            $session = $this->engine->start(
                $request->definition,
                $contact,
                $request->initialState,
                FlowRunOrigin::conversation(chainDepth: $request->chainDepth, startKey: $startKey),
            );
        } catch (UniqueConstraintViolationException $exception) {
            // A duplicate of this start won the race past the lock (its TTL lapsed): it started once.
            if (null !== $this->sessions->findByStartKey($startKey)) {
                return ContactFlowStartOutcome::of(ContactFlowStartStatus::RaceLost);
            }

            throw $exception;
        }

        return ContactFlowStartOutcome::started($session, count($verdict->stale));
    }
}
