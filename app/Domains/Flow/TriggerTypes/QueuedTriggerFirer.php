<?php

declare(strict_types=1);

namespace App\Domains\Flow\TriggerTypes;

use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Flow\Services\EventChainContext;
use App\Jobs\Flow\FireRegisteredTriggerJob;
use Fapost\Foundation\Flow\Contracts\TriggerFirerInterface;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Core's {@see TriggerFirerInterface}: checks the fire, then queues its fan-out ({@see FireRegisteredTriggerJob}) on
 * `scheduled.triggers` after the caller's transaction commits, as a Core event's is.
 *
 * Here, before anything is queued, the fire gets its fire key (`tt:<key>`, {@see RegisteredTriggerFireKey}) and its
 * chain depth: one deeper than the session running right now, if any ({@see EventChainContext}); a fire outside an
 * engine call (an extension's own queued job) starts a chain at 1, so a loop through such a job is the extension's to
 * break (`adr-20261123-solution-trigger-types`). A fire past
 * {@see FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH} starts nothing and is logged
 * (`flow.trigger.chain_depth_exceeded`). Whether the type's Solution is active is the job's question: it is asked in
 * the tenant's context.
 *
 * Any extension's code may fire any registered type; Core checks only that the type is registered and, in the job,
 * that its Solution is active for the tenant (an accepted risk, `adr-20261123-solution-trigger-types`).
 */
final readonly class QueuedTriggerFirer implements TriggerFirerInterface
{
    public function __construct(
        private TriggerTypeRegistry $types,
        private EventChainContext $eventChain,
        private LoggerInterface $logger,
    ) {
    }

    public function fire(string $tenantId, string $type, string $fireKey, array $payload): void
    {
        if ('' === $tenantId) {
            throw new InvalidArgumentException('A trigger fire needs a tenant id.');
        }

        if (! $this->types->has($type)) {
            throw new InvalidArgumentException("Trigger type \"{$type}\" is not registered.");
        }

        if ('' === $fireKey) {
            throw new InvalidArgumentException("A fire of trigger type \"{$type}\" needs a fire key.");
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("The payload of trigger type \"{$type}\" is not JSON-serializable: {$exception->getMessage()}", 0, $exception);
        }

        if (mb_strlen($json, '8bit') > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(sprintf('The payload of trigger type "%s" is larger than %d bytes.', $type, self::MAX_PAYLOAD_BYTES));
        }

        $depth = $this->eventChain->current() + 1;

        if ($depth > FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH) {
            $this->logger->warning('flow.trigger.chain_depth_exceeded', [
                'tenant_id'    => $tenantId,
                'trigger_type' => $type,
                'depth'        => $depth,
                'max_depth'    => FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH,
            ]);

            return;
        }

        FireRegisteredTriggerJob::dispatch($tenantId, $type, RegisteredTriggerFireKey::from($fireKey), $payload, $depth)
            ->afterCommit();
    }
}
