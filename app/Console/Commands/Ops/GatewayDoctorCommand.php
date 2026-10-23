<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Channels\Services\IngressMigrator;
use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Webhook\Enums\IngressDriver;
use App\Domains\Webhook\Enums\SpecStatus;
use App\Domains\Webhook\Services\IngressSpecPublisher;
use App\Domains\Webhook\Services\IngressSpecResolver;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Checks that the application and the gateway can actually see each other.
 *
 * The two processes share exactly one channel — Redis — and every way that
 * channel can be misconfigured fails silently. A wrong key prefix, unpublished
 * specs or a stale gateway URL all leave both sides looking healthy while
 * webhooks quietly take the slow path or none at all. This command asks the
 * questions that would otherwise only be answered by an incident.
 */
final class GatewayDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'gateway:doctor
        {--url= : Gateway base URL to probe (default: the configured one)}';

    /** @var string */
    protected $description = 'Verify that the gateway and the application share the same Redis, specs and routing';

    private int $problems = 0;

    public function __construct(
        private readonly Repository $config,
        private readonly IngressSpecResolver $specs,
        private readonly WebhookUrlGenerator $urls,
        private readonly IngressMigrator $migrator,
        private readonly IngressSpecPublisher $publisher,
        private readonly GatewayProbe $gateway,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info('Gateway connectivity check');

        $this->checkDriver();
        $this->checkRedis();
        $this->checkSpecs();
        $this->checkGateway();
        $this->checkDrift();

        $this->newLine();

        if ($this->problems > 0) {
            $this->components->error(sprintf('%d problem(s) found.', $this->problems));

            return self::FAILURE;
        }

        $this->components->info('Everything checks out.');

        return self::SUCCESS;
    }

    private function checkDriver(): void
    {
        $driver = IngressDriver::fromConfig($this->config->get('webhook.ingress.driver'));

        $this->components->twoColumnDetail('Ingress driver', $driver->value);

        if (IngressDriver::Gateway !== $driver) {
            $this->components->warn(
                'The driver is not "gateway", so new channels register against the application. '
                . 'Run php artisan gateway:install to change that.'
            );

            return;
        }

        $gatewayUrl = (string) $this->config->get('webhook.ingress.gateway_url');

        if ('' === $gatewayUrl) {
            $this->reportProblem('WEBHOOK_GATEWAY_URL is empty, so the driver silently falls back to the application.');

            return;
        }

        $this->components->twoColumnDetail('Gateway URL', $gatewayUrl);
    }

    /**
     * The prefix is the subtlest failure of the lot: if it differs, every lookup
     * misses, the gateway proxies everything, and nothing anywhere reports an error.
     */
    private function checkRedis(): void
    {
        try {
            Redis::ping();
        } catch (Throwable $throwable) {
            $this->reportProblem('Redis is unreachable: ' . $throwable->getMessage());

            return;
        }

        $prefix = (string) $this->config->get('database.redis.options.prefix');

        $this->components->twoColumnDetail('Redis', 'reachable');
        $this->components->twoColumnDetail(
            'Key prefix',
            '' === $prefix ? '(none)' : $prefix,
        );

        $this->line(
            '    The gateway must resolve the same prefix from REDIS_PREFIX, or it will read an empty registry.'
        );
    }

    private function checkSpecs(): void
    {
        $declarative = $this->specs->declarativePlatforms();

        if ([] === $declarative) {
            $this->components->warn(
                'No adapter publishes an ingress spec, so the gateway cannot verify any platform '
                . 'and will proxy every delivery.'
            );

            return;
        }

        try {
            $inspected = $this->publisher->inspect();
        } catch (Throwable) {
            // Redis cannot be read, so nothing can be shown as published.
            $inspected = array_fill_keys($declarative, ['status' => SpecStatus::Missing, 'scheme' => null]);
        }

        foreach ($inspected as $platform => $spec) {
            if (SpecStatus::Missing === $spec['status']) {
                $this->reportProblem(
                    "No spec published for {$platform}. Run php artisan ops:ingress-specs-publish."
                );

                continue;
            }

            if (SpecStatus::Differs === $spec['status']) {
                $this->reportProblem(
                    "The published spec for {$platform} differs from the adapter's. "
                    . 'Republish with php artisan ops:ingress-specs-publish.'
                );

                continue;
            }

            $this->components->twoColumnDetail(
                "Spec: {$platform}",
                $spec['scheme'] . ' (published, matches adapter)',
            );
        }
    }

    /**
     * Probes the gateway's health endpoint. A failure here is not necessarily
     * fatal — the gateway may sit on a network this host cannot reach — so it is
     * reported as a warning rather than a problem.
     */
    private function checkGateway(): void
    {
        $base = (string) ($this->option('url') ?: $this->config->get('webhook.ingress.gateway_url'));

        if ('' === $base) {
            return;
        }

        $health = $this->gateway->probeEndpoint($base);
        $url    = (string) $health->details['url'];

        if (HealthState::Unknown === $health->state) {
            $this->components->warn(
                "Could not reach {$url}: {$health->details['error']}. "
                . 'If the gateway is not deployed yet, this is expected.'
            );

            return;
        }

        if (HealthState::Down === $health->state) {
            $this->reportProblem($health->reason);

            return;
        }

        $this->components->twoColumnDetail('Gateway health', 'ok');
    }

    /**
     * Channels are registered with whatever URL was current at the time, so a
     * driver switch leaves the existing ones behind until they are re-registered.
     */
    private function checkDrift(): void
    {
        try {
            $drift = $this->migrator->drift();
        } catch (Throwable $throwable) {
            $this->components->warn('Could not read channel routing: ' . $throwable->getMessage());

            return;
        }

        if ([] === $drift) {
            $this->components->twoColumnDetail('Channel routing', 'all on the configured ingress');

            return;
        }

        foreach ($drift as $platform => $count) {
            $this->components->twoColumnDetail("Behind: {$platform}", "{$count} channel(s)");
        }

        $this->components->warn(
            'These still deliver to the URL they were registered with. '
            . 'Migrate them with php artisan ops:ingress-migrate --apply.'
        );
    }

    private function reportProblem(string $message): void
    {
        $this->problems++;
        $this->components->error($message);
    }
}
