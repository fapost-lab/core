<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Database\TenantPostgresConnection;
use App\Domains\Tenancy\Infrastructure\EloquentWebhookRegistryReader;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\ConfigTenantResolver;
use App\Domains\Tenancy\Services\CoreBootstrap;
use App\Domains\Tenancy\Services\DomainBootstrapper;
use App\Domains\Tenancy\Services\HostTenantResolver;
use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

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
        ));
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
        $this->app->scoped(TenantSwitcher::class);
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
     * Slugs no tenant may claim: the configured list plus the platform's own
     * ingress hostnames, minus the default tenant slug.
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

        foreach ($this->ingressLabels($config) as $label) {
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
