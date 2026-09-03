<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Webhook\Deployment\EnvFile;
use App\Domains\Webhook\Deployment\GatewayArtifacts;
use App\Domains\Webhook\Deployment\GatewayPlan;
use App\Domains\Webhook\Deployment\LogTarget;
use App\Domains\Webhook\Deployment\RunMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use RuntimeException;
use Throwable;

/**
 * Interactive installer for the webhook ingress gateway.
 *
 * The gateway is optional, and the application is fully functional without it,
 * so nothing here is assumed: the operator is asked what they are deploying onto
 * and every generated file follows from those answers.
 *
 * Deliberately conservative about the host. It writes inside the project and
 * prints what to do next, rather than dropping units into /etc or restarting
 * services — an installer that reaches into system directories is one nobody can
 * safely run twice.
 */
final class InstallGatewayCommand extends Command
{
    protected $signature = 'gateway:install
        {--disable : Point new channels back at the application and skip the gateway}
        {--output= : Directory for generated deployment files (default: gateway/dist)}';

    protected $description = 'Configure the webhook ingress gateway and generate its deployment files';

    public function handle(): int
    {
        $this->components->info('FaPost webhook gateway setup');

        $env = new EnvFile(base_path('.env'));

        if (! $env->exists()) {
            $this->components->error('No .env file found. Copy .env.example first.');

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            return $this->disable($env);
        }

        $this->explain();

        if (! confirm('Set up the gateway now?', default: true)) {
            $this->components->warn('Nothing changed. The application keeps serving webhooks itself.');

            return self::SUCCESS;
        }

        $plan = $this->buildPlan($env);

        $env->set($plan->environment(), 'Webhook ingress gateway (php artisan gateway:install)');
        $this->components->info('Updated .env');

        $written = $this->writeArtifacts($plan);

        $this->publishSpecs();

        if ($plan->runMode->buildsLocally()) {
            $this->build($plan);
        }

        $this->summarize($plan, $written);

        return self::SUCCESS;
    }

    /**
     * Explain the trade-off before asking anything.
     *
     * Someone running this on a small install may not need a gateway at all, and
     * it is cheaper to say so here than to have them discover it after deploying.
     */
    private function explain(): void
    {
        $this->line('  The gateway is a small Go service that accepts provider webhooks,');
        $this->line('  verifies them and queues them, taking that load off PHP.');
        $this->newLine();
        $this->line('  It is optional. Without it the application handles webhooks itself,');
        $this->line('  which is fine until webhook volume becomes a bottleneck.');
        $this->newLine();
        $this->line('  Both paths stay valid at all times, so switching is reversible and');
        $this->line('  existing channels keep working until you migrate them.');
        $this->newLine();
    }

    private function buildPlan(EnvFile $env): GatewayPlan
    {
        $runMode = select(
            label: 'How will the gateway run?',
            options: collect(RunMode::cases())->mapWithKeys(
                static fn (RunMode $mode): array => [$mode->value => $mode->label()]
            )->all(),
            default: RunMode::Systemd->value,
        );

        $runMode = RunMode::from($runMode);

        $publicUrl = text(
            label: 'Public URL providers will deliver to',
            placeholder: 'https://webhook.example.com',
            default: $this->suggestPublicUrl($env),
            required: true,
            validate: static fn (string $value): ?string => str_starts_with($value, 'https://')
                || str_starts_with($value, 'http://')
                    ? null
                    : 'Include the scheme, e.g. https://webhook.example.com',
            hint: 'Telegram requires a valid TLS certificate here.',
        );

        $upstreamUrl = text(
            label: "The application's own URL (used when the gateway falls back to it)",
            default: $this->suggestUpstream($env),
            required: true,
            validate: static fn (string $value): ?string => str_starts_with($value, 'https://')
                || str_starts_with($value, 'http://')
                    ? null
                    : 'Include the scheme, e.g. https://app.example.com',
            hint: 'On a cache miss the gateway forwards here rather than dropping the delivery.',
        );

        $listenAddr = text(
            label: 'Address the gateway listens on',
            default: RunMode::Docker === $runMode ? ':8080' : '127.0.0.1:8080',
            required: true,
            hint: 'Bind to localhost when a TLS terminator sits in front on the same host.',
        );

        $logTarget = LogTarget::from(select(
            label: 'Where should the gateway log?',
            options: collect(LogTarget::cases())->mapWithKeys(
                static fn (LogTarget $target): array => [$target->value => $target->label()]
            )->all(),
            default: RunMode::Docker === $runMode ? LogTarget::Stdout->value : LogTarget::Stdout->value,
        ));

        $logPath = LogTarget::File === $logTarget
            ? text(label: 'Log file path', default: '/var/log/fapost/gateway.log', required: true)
            : '';

        $trustedProxies = $this->askTrustedProxies();

        return new GatewayPlan(
            runMode: $runMode,
            publicUrl: mb_rtrim($publicUrl, '/'),
            listenAddr: $listenAddr,
            upstreamUrl: mb_rtrim($upstreamUrl, '/'),
            logTarget: $logTarget,
            logPath: $logPath,
            logLevel: select(
                label: 'Log level',
                options: ['info' => 'info (recommended)', 'debug' => 'debug', 'warn' => 'warn', 'error' => 'error'],
                default: 'info',
            ),
            trustedProxies: $trustedProxies,
            ratePerSecond: (float) text(
                label: 'Rate limit per channel, requests per second',
                default: '30',
                hint: 'Set 0 to disable. This protects the queue, not the provider.',
            ),
            rateBurst: (int) text(label: 'Burst allowance per channel', default: '60'),
            installDir: RunMode::Docker === $runMode
                ? '/usr/local/bin'
                : text(label: 'Where should the binary be installed?', default: '/usr/local/bin', required: true),
            serviceUser: RunMode::Systemd === $runMode
                ? text(label: 'System user to run the service as', default: 'www-data', required: true)
                : 'www-data',
        );
    }

    /**
     * @return list<string>
     */
    private function askTrustedProxies(): array
    {
        $this->newLine();
        $this->line('  If a reverse proxy sits in front, its address must be trusted before');
        $this->line('  X-Forwarded-For is believed — otherwise any caller could forge a client');
        $this->line('  address and slip past the rate limit.');

        $raw = text(
            label: 'Trusted proxy addresses (comma separated, blank if none)',
            placeholder: '127.0.0.1, 10.0.0.5',
            hint: 'Leave blank to ignore X-Forwarded-For entirely.',
        );

        return collect(explode(',', $raw))
            ->map(static fn (string $value): string => mb_trim($value))
            ->filter()
            ->values()
            ->all();
    }

    private function disable(EnvFile $env): int
    {
        $env->set(['WEBHOOK_INGRESS_DRIVER' => 'laravel']);

        $this->components->info('New channels will register against the application.');
        $this->components->warn(
            'Channels already pointed at the gateway keep using it until you run: '
            . 'php artisan ops:ingress-migrate --apply'
        );

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function writeArtifacts(GatewayPlan $plan): array
    {
        $directory = (string) ($this->option('output') ?: base_path('gateway/dist'));

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create output directory: {$directory}");
        }

        $written = [];

        foreach ((new GatewayArtifacts($plan))->all() as $name => $contents) {
            $path = $directory . '/' . $name;
            file_put_contents($path, mb_rtrim($contents) . "\n");

            if (str_ends_with($name, '.sh')) {
                chmod($path, 0o755);
            }

            $written[] = $path;
        }

        return $written;
    }

    /**
     * Publish ingress specs before any traffic can reach the gateway.
     *
     * Without them it has nothing to verify signatures against and would proxy
     * every delivery straight back to the application.
     */
    private function publishSpecs(): void
    {
        $this->components->task('Publishing ingress specs to Redis', static function (): void {
            Artisan::call('ops:ingress-specs-publish');
        });
    }

    private function build(GatewayPlan $plan): void
    {
        if (null === $this->findGo()) {
            $this->components->warn(
                'Go was not found, so the binary was not built. Install Go 1.27+ and run: '
                . '(cd gateway && make build)'
            );

            return;
        }

        $built = false;

        $this->components->task('Building the gateway binary', function () use (&$built): void {
            $output = [];
            $status = 0;

            exec('cd ' . escapeshellarg(base_path('gateway')) . ' && make build 2>&1', $output, $status);

            $built = 0 === $status;

            if (! $built) {
                throw new RuntimeException(implode("\n", array_slice($output, -5)));
            }
        });

        if ($built) {
            $this->components->info(
                "Binary at gateway/bin/gateway — install it with: sudo install -m 0755 gateway/bin/gateway {$plan->installDir}/gateway"
            );
        }
    }

    private function findGo(): ?string
    {
        try {
            $output = [];
            $status = 0;

            exec('command -v go 2>/dev/null', $output, $status);

            return 0 === $status && [] !== $output ? $output[0] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $written
     */
    private function summarize(GatewayPlan $plan, array $written): void
    {
        $this->newLine();
        $this->components->info('Generated deployment files');

        foreach ($written as $path) {
            $this->line('  ' . str_replace(base_path() . '/', '', $path));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Run mode', $plan->runMode->label());
        $this->components->twoColumnDetail('Public URL', $plan->publicUrl);
        $this->components->twoColumnDetail('Listens on', $plan->listenAddr);
        $this->components->twoColumnDetail('Falls back to', $plan->upstreamUrl);

        $this->newLine();
        $this->line('  Next: follow gateway/dist/README.md, then verify with:');
        $this->line('      php artisan gateway:doctor');
        $this->newLine();
        $this->line('  Existing channels keep using the application URL until you run:');
        $this->line('      php artisan ops:ingress-migrate --apply');
        $this->newLine();
    }

    private function suggestPublicUrl(EnvFile $env): string
    {
        $existing = $env->get('WEBHOOK_GATEWAY_URL');

        if (null !== $existing) {
            return $existing;
        }

        $host = parse_url((string) $env->get('APP_URL'), PHP_URL_HOST);

        return is_string($host) ? 'https://webhook.' . $host : '';
    }

    private function suggestUpstream(EnvFile $env): string
    {
        foreach (['GATEWAY_UPSTREAM_URL', 'WEBHOOK_BASE_URL', 'APP_URL'] as $key) {
            $value = $env->get($key);

            // WEBHOOK_BASE_URL is not required to carry a scheme today, and a
            // schemeless value would fail the gateway's own validation on boot.
            if (null !== $value && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))) {
                return $value;
            }
        }

        return '';
    }
}
