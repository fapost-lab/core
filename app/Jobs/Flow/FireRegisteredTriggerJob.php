<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The fan-out of one fire of a registered trigger type ({@see \App\Domains\Flow\TriggerTypes\QueuedTriggerFirer}):
 * in the tenant's context, one {@see StartAutomationRunJob} per active trigger of the type whose config the type
 * {@see \Fapost\Foundation\Flow\Contracts\TriggerTypeInterface::matches()} the payload. Each run is contactless, runs
 * on the trigger's assistant if it has one, holds the payload as `flow.trigger.*` and the type as
 * `flow.trigger_type`, and carries the fire's key, so a retry of this job starts each run once.
 *
 * Nothing starts while the type's Solution is not active for the tenant, or when the type is no longer registered
 * (the Solution was removed after the fire was queued). A type whose `matches()` throws skips that trigger only.
 */
final class FireRegisteredTriggerJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    public const string QUEUE = 'scheduled.triggers';

    /**
     * @param  string  $fireKey  already namespaced (`tt:…`)
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $type,
        public readonly string $fireKey,
        public readonly array $payload,
        public readonly int $chainDepth = 1,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        TriggerTypeRegistry $types,
        SolutionAvailabilityInterface $availability,
        FlowTriggerRepositoryInterface $triggers,
        LoggerInterface $logger,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($types, $availability, $triggers, $logger): void {
            $context = ['tenant_id' => $this->tenantId, 'trigger_type' => $this->type];

            if (! $types->has($this->type)) {
                $logger->warning('flow.trigger.type_not_registered', $context);

                return;
            }

            $owner = $types->ownerOf($this->type);

            if (null !== $owner && ! $availability->isAvailable($owner)) {
                $logger->info('flow.trigger.solution_inactive', [...$context, 'solution' => $owner]);

                return;
            }

            $triggerType = $types->get($this->type);

            foreach ($triggers->getActiveOfType($this->tenantId, $this->type) as $trigger) {
                $config = is_array($trigger->config) ? $trigger->config : [];

                try {
                    $matches = $triggerType->matches($config, $this->payload);
                } catch (Throwable $exception) {
                    $logger->warning('flow.trigger.matches_failed', [...$context, 'trigger_id' => $trigger->id, 'error' => $exception->getMessage()]);

                    continue;
                }

                if (! $matches) {
                    continue;
                }

                StartAutomationRunJob::dispatch(
                    $this->tenantId,
                    $trigger->flow_id,
                    $trigger->id,
                    $this->fireKey,
                    ['flow' => ['trigger' => $this->payload, 'trigger_type' => $this->type]],
                    is_string($trigger->assistant_id) && '' !== $trigger->assistant_id ? $trigger->assistant_id : null,
                    $this->chainDepth,
                );
            }
        });
    }
}
