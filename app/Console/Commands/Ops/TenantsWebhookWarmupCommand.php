<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Webhook\Services\IngressSpecPublisher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repopulates Redis webhook routing after flush or corruption. DB remains source of truth.
 */
final class TenantsWebhookWarmupCommand extends Command
{
    /** @var string */
    protected $signature = 'ops:webhook-warmup
        {--tenant=* : Tenant slug(s); ignored when --all is used}
        {--all : Run for all active tenants}';

    /** @var string */
    protected $description = 'Write-through warmup: push all active channels to Redis webhook registry per tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly ChannelWebhookRegistryInterface $channelWebhookRegistry,
        private readonly IngressSpecPublisher $ingressSpecPublisher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Specs are republished as part of warmup, not left to a separate command.
        // The application itself never reads them — it asks the adapter directly —
        // so a missing spec is invisible here and only breaks the external gateway,
        // which has no way to rebuild one. Anything that repopulates Redis for
        // ingress must repopulate all of it.
        $this->publishIngressSpecs();

        $active = $this->tenantRepository->findAllActive();

        if ([] === $active) {
            $this->components->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $selected = $this->option('all')
            ? $active
            : $this->resolveTenantsBySlug($active, $this->option('tenant') ?? []);

        if ([] === $selected) {
            $this->components->warn('No tenants selected. Use --all or --tenant=slug.');

            return self::SUCCESS;
        }

        $ok   = 0;
        $fail = 0;

        foreach ($selected as $tenant) {
            $slug = $tenant->getSlug();
            $this->line("[{$slug}] warmup …");

            try {
                $this->tenantSwitcher->runForTenant($tenant, function () use ($tenant): void {
                    $this->channelWebhookRegistry->warmup($tenant);
                });
                $this->line("[{$slug}] <info>OK</info>");
                $ok++;
            } catch (Throwable $throwable) {
                $fail++;
                $this->line("[{$slug}] <error>{$throwable->getMessage()}</error>");
            }
        }

        $this->components->twoColumnDetail('Success', (string)$ok);
        $this->components->twoColumnDetail('Failed', (string)$fail);

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Republish declarative ingress specs, reporting rather than aborting on failure.
     *
     * Spec publication is independent of tenant routing: losing it must not stop
     * the warmup that repopulates the registry, which is the more urgent half.
     */
    private function publishIngressSpecs(): void
    {
        try {
            $published = $this->ingressSpecPublisher->publishAll();

            $this->components->twoColumnDetail('ingress specs', sprintf('%d published', count($published)));
        } catch (Throwable $throwable) {
            $this->components->warn("Ingress spec publication failed: {$throwable->getMessage()}");
        }
    }

    /**
     * @param  array<int, TenantInterface>  $active
     * @param  array<int, string>  $slugs
     *
     * @return array<int, TenantInterface>
     */
    private function resolveTenantsBySlug(array $active, array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        $bySlug = [];
        foreach ($active as $tenant) {
            $bySlug[mb_strtolower($tenant->getSlug())] = $tenant;
        }

        $out = [];
        foreach ($slugs as $slug) {
            $key = mb_strtolower((string)$slug);
            if (isset($bySlug[$key])) {
                $out[] = $bySlug[$key];
            }
        }

        return $out;
    }
}
