<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\SupportAccessRedeemerInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Database\TenantPostgresConnection;
use App\Domains\Tenancy\Infrastructure\CoreSupportAccess;
use App\Domains\Tenancy\Infrastructure\CoreTenantDirectory;
use App\Domains\Tenancy\Infrastructure\CoreTenantProvisioner;
use App\Domains\Tenancy\Infrastructure\CoreTenantReservations;
use App\Domains\Tenancy\Infrastructure\EloquentWebhookRegistryReader;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\AlwaysActiveAccessMode;
use App\Domains\Tenancy\Services\ConfigTenantResolver;
use App\Domains\Tenancy\Services\CoreBootstrap;
use App\Domains\Tenancy\Services\CurrentAccessState;
use App\Domains\Tenancy\Services\DomainBootstrapper;
use App\Domains\Tenancy\Services\HostModeDeploymentCheck;
use App\Domains\Tenancy\Services\HostTenantResolver;
use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\RecordQuota;
use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Tenancy\Contracts\SupportAccessInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantDirectoryInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantProvisionerInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantReservationInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenancy domain provider.
 *
 * Registers tenant-context, tenant database management, runtime bootstrapping, and webhook registry writing.
 */
final class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register tenancy bindings (scoped/singleton) for per-request tenant isolation.
     */
    public function register(): void
    {
        // Every pgsql connection (landlord included, not only the tenant one)
        // can then have its search_path moved while it stays open. The class
        // adds nothing else; TenantDatabaseManager relies on it to switch
        // tenants without dropping a transaction that is already running.
        Connection::resolverFor(
            'pgsql',
            static fn ($pdo, string $database, string $prefix, array $config): TenantPostgresConnection => new TenantPostgresConnection($pdo, $database, $prefix, $config),
        );

        $this->app->scoped(TenantContextInterface::class, TenantContext::class);

        $this->app->scoped(
            TenantDatabaseManagerInterface::class,
            TenantDatabaseManager::class,
        );

        $this->app->bind(
            TenantRepositoryInterface::class,
            TenantRepository::class,
        );

        // The mode is read (and validated) when the resolver is built, not at boot: a bad value must
        // fail the first tenant use, but must not stop `config:clear` from fixing it.
        $this->app->bind(TenantResolverInterface::class, fn ($app): TenantResolverInterface => match (TenancyResolutionMode::fromConfig()) {
            TenancyResolutionMode::Single => $app->make(ConfigTenantResolver::class),
            TenancyResolutionMode::Host   => $app->make(HostTenantResolver::class),
        });
        $this->app->bind(RequestHostClassifier::class, fn ($app): RequestHostClassifier => new RequestHostClassifier(
            TenancyResolutionMode::fromConfig(),
            (string) $app['config']->get('tenancy.base_domain'),
            $app['config']->get('tenancy.default_tenant_slug'),
            $app->make(TenantSlugPolicy::class),
            $this->platformSubdomains($app['config']),
        ));
        $this->app->bind(TenantProvisionerInterface::class, CoreTenantProvisioner::class);
        // Plain bind as well: Core is the only implementation of slug reservation, and nothing in Core calls it.
        $this->app->bind(TenantReservationInterface::class, CoreTenantReservations::class);
        $this->app->bind(TenantDirectoryInterface::class, CoreTenantDirectory::class);

        // Core is the only implementation of support access, so a plain bind: an operator package
        // calls the contract and never replaces it. Without the package the feature stays off
        // (`tenancy.support_access.enabled`), not unbound.
        $this->app->bind(SupportAccessInterface::class, CoreSupportAccess::class);
        $this->app->bind(SupportAccessRedeemerInterface::class, SupportAccessTokenStore::class);

        $this->app->when(TenantProvisioningService::class)
            ->needs('$leaseSeconds')
            ->giveConfig('tenancy.provisioning.lease_seconds', 900);

        $this->app->when(ConfigTenantResolver::class)
            ->needs('$defaultTenantSlug')
            ->giveConfig('tenancy.default_tenant_slug');

        $this->app->bind(
            TenantSlugPolicy::class,
            fn ($app): TenantSlugPolicy => new TenantSlugPolicy($this->reservedSlugs($app['config'])),
        );

        $this->app->scoped(CoreBootstrap::class);
        $this->app->scoped(CoreBootstrapInterface::class, fn ($app): CoreBootstrap => $app->make(CoreBootstrap::class));
        $this->app->scoped(DomainBootstrapper::class);
        $this->app->scoped(TenantSwitcher::class, fn ($app): TenantSwitcher => new TenantSwitcher(
            $app->make(TenantContextInterface::class),
            $app->make(TenantDatabaseManagerInterface::class),
            $app->make(PermissionRegistrar::class),
            (string) config('permission.cache.key'),
        ));
        $this->registerQuota();
        $this->registerAccessMode();

        $this->app->singleton(WebhookRegistryWriterInterface::class, WebhookRegistryWriter::class);
        $this->app->singleton(WebhookRegistryReaderInterface::class, EloquentWebhookRegistryReader::class);

        // CoreBootstrap memoizes the booted tenant id; once TenantSwitcher restores
        // the previous context that memo is stale and must be dropped, otherwise a
        // subsequent boot() within the same scope would be skipped for the wrong tenant.
        $this->app->afterResolving(TenantSwitcher::class, function (TenantSwitcher $switcher, $app): void {
            $switcher->registerRestoreHook(function () use ($app): void {
                $app->make(CoreBootstrapInterface::class)->reset();
            });
        });
    }

    /**
     * Close the limit registry once every provider has registered its keys.
     */
    public function boot(): void
    {
        $this->checkHostModeDeployment();

        $this->app->booted(function (): void {
            if (! $this->app->environment('testing')) {
                $this->app->make(LimitRegistry::class)->freeze();
            }
        });
    }

    /**
     * Refuses to serve web requests in `host` mode with sessions that cannot work there.
     *
     * Console and queue processes boot without it: they hold no session, and a worker that
     * dies on a web-only setting could not even run `config:clear` to repair it. `about` lists
     * what is wrong either way, including advice that never stops a request.
     */
    private function checkHostModeDeployment(): void
    {
        $check = new HostModeDeploymentCheck($this->app['config']);

        AboutCommand::add('Tenancy', static fn (): array => [
            'Host mode problems' => [] === ($found = [...$check->errors(), ...$check->warnings()]) ? 'none' : implode(' ', $found),
        ]);

        if (! $this->app->runningInConsole()) {
            $check->assertWorkable();
        }
    }

    /**
     * Limit registry, the default "no limits" answer and the record quota service.
     *
     * Package providers register before application providers, so the default is bound with
     * bindIf: an operator package that already bound {@see TenantLimitsInterface} keeps its binding.
     */
    private function registerQuota(): void
    {
        $this->app->singleton(LimitRegistry::class, fn (): LimitRegistry => new LimitRegistry());
        $this->app->singleton(LimitRegistryInterface::class, fn ($app): LimitRegistry => $app->make(LimitRegistry::class));
        $this->app->bindIf(TenantLimitsInterface::class, UnlimitedTenantLimits::class);
        // Not a singleton: it reads the scoped tenant context.
        $this->app->bind(RecordQuotaInterface::class, RecordQuota::class);
    }

    /**
     * The default "everything is active" access mode and the per-request state it feeds.
     *
     * Bound with bindIf for the same reason as the limits: an operator package that already bound
     * {@see TenantAccessModeInterface} keeps its binding.
     */
    private function registerAccessMode(): void
    {
        $this->app->bindIf(TenantAccessModeInterface::class, AlwaysActiveAccessMode::class);
        $this->app->scoped(CurrentAccessState::class);
    }

    /**
     * Slugs no tenant may claim: the configured list plus the platform's own
     * ingress hostnames and declared platform subdomains, minus the default tenant slug.
     *
     * Ingress hostnames are derived rather than listed so that pointing the
     * gateway at a different subdomain reserves that name automatically. A
     * hand-maintained list would silently fall out of step with the URL that
     * actually receives webhooks.
     *
     * @return list<string>
     */
    private function reservedSlugs(ConfigRepository $config): array
    {
        $reserved = array_map(mb_strtolower(...), (array) $config->get('tenancy.reserved_slugs', []));

        foreach ([...$this->ingressLabels($config), ...$this->platformSubdomains($config)] as $label) {
            $reserved[] = $label;
        }

        // The stock single-tenant installation provisions the default tenant, so its own slug
        // must stay assignable even when the list would otherwise claim it. With one tenant per
        // host there is no default tenant, and `app.<base>` is as reserved as any other name.
        $default = TenancyResolutionMode::Host === TenancyResolutionMode::tryFrom((string) $config->get('tenancy.resolution'))
            ? ''
            : mb_strtolower((string) $config->get('tenancy.default_tenant_slug'));

        return array_values(array_filter(
            array_unique($reserved),
            static fn (string $slug): bool => '' !== $slug && $slug !== $default,
        ));
    }

    /**
     * Declared platform subdomains, read at resolve time so that a label an operator
     * package adds while registering is seen.
     *
     * @return list<string>
     */
    private function platformSubdomains(ConfigRepository $config): array
    {
        $labels = array_map(
            static fn (mixed $label): string => mb_strtolower(mb_trim((string) $label)),
            (array) $config->get('tenancy.platform_subdomains', []),
        );

        return array_values(array_unique(array_filter($labels, static fn (string $label): bool => '' !== $label)));
    }

    /**
     * Subdomain labels of the platform's own ingress URLs, when they sit under
     * the tenancy base domain.
     *
     * @return list<string>
     */
    private function ingressLabels(ConfigRepository $config): array
    {
        $baseDomain = mb_strtolower((string) $config->get('tenancy.base_domain'));

        if ('' === $baseDomain) {
            return [];
        }

        $labels = [];

        foreach (['webhook.base_url', 'webhook.ingress.gateway_url'] as $key) {
            $host = parse_url((string) $config->get($key), PHP_URL_HOST);

            if (! is_string($host)) {
                continue;
            }

            $suffix = '.' . $baseDomain;
            $host   = mb_strtolower($host);

            if (! str_ends_with($host, $suffix)) {
                continue;
            }

            $label = mb_substr($host, 0, -mb_strlen($suffix));

            // Only a direct child of the base domain maps onto a tenant slug.
            if ('' !== $label && ! str_contains($label, '.')) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
