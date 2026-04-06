<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

use RuntimeException;
use Throwable;

/**
 * Runs {@see TenantAclSeeder::run()} per selected active tenant via {@see TenantSwitcher}.
 */
final class TenantsSeedAclCommand extends Command
{
    protected $signature = 'ops:tenants-seed-acl
        {--tenant=* : Tenant slug(s); ignored when --all is used}
        {--all : Run for all active tenants (skips interactive selection)}';

    protected $description = 'Run TenantAclSeeder (domain permissions + default roles) for selected active tenant(s)';

    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantSwitcher $tenantSwitcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $active = $this->tenantRepository->findAllActive();

        if ([] === $active) {
            $this->components->warn('No active tenants found.');

            return self::SUCCESS;
        }

        try {
            $selected = $this->resolveTenants($active);
        } catch (RuntimeException $runtimeException) {
            $this->components->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        if ([] === $selected) {
            $this->components->warn('No tenants selected.');

            return self::SUCCESS;
        }

        $count = count($selected);
        $this->components->info("Running TenantAclSeeder for {$count} tenant(s).");
        $this->newLine();

        $success     = 0;
        $fail        = 0;
        $failedSlugs = [];

        foreach ($selected as $tenant) {
            $slug = $tenant->getSlug();
            $this->line("[{$slug}] → TenantAclSeeder …");

            try {
                $this->tenantSwitcher->runForTenant($tenant, function (): void {
                    new RoleSeeder()->run();
                });
                $this->line("[{$slug}] → <info>OK</info>");
                $success++;
            } catch (Throwable $throwable) {
                $fail++;
                $failedSlugs[] = $slug;
                $this->line("[{$slug}] → <error>FAILED</error> " . $throwable->getMessage());

                if ($this->output->isVerbose()) {
                    $this->line($throwable->getTraceAsString());
                }
            }

            $this->newLine();
        }

        $this->components->twoColumnDetail('Success', (string) $success);
        $this->components->twoColumnDetail('Failed', (string) $fail);

        if ([] !== $failedSlugs) {
            $this->newLine();
            $this->components->error('Failed tenant slugs: ' . implode(', ', $failedSlugs));
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, TenantInterface>  $active
     * @return array<int, TenantInterface>
     */
    private function resolveTenants(array $active): array
    {
        $bySlug = [];
        foreach ($active as $tenant) {
            $bySlug[mb_strtolower($tenant->getSlug())] = $tenant;
        }

        if ($this->option('all')) {
            return array_values($active);
        }

        $requested = array_unique(array_map(
            static fn (mixed $slug): string => (string) $slug,
            $this->option('tenant') ?? [],
        ));

        if ([] !== $requested) {
            $out = [];
            foreach ($requested as $slug) {
                $key = mb_strtolower($slug);
                if ( ! isset($bySlug[$key])) {
                    throw new RuntimeException(
                        sprintf('Active tenant with slug [%s] not found.', $slug),
                    );
                }
                $out[] = $bySlug[$key];
            }

            return $out;
        }

        if ($this->laravel->runningInConsole() && $this->input->isInteractive()) {
            $labels = [];
            foreach ($active as $tenant) {
                $labels[$tenant->getSlug()] = $tenant->getSlug() . ' (' . $tenant->getSchemaName() . ')';
            }

            $keys   = array_keys($labels);
            $chosen = multiselect(
                label: 'Which tenant(s) should receive ACL seed?',
                options: $labels,
                default: $keys,
                required: true,
            );

            $out = [];
            foreach ($chosen as $slug) {
                $out[] = $bySlug[mb_strtolower((string) $slug)];
            }

            return $out;
        }

        return array_values($active);
    }
}
