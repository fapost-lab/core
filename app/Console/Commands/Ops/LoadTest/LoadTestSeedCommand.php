<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestCodeGenerator;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestFlowBlueprint;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestStateStore;
use App\Console\Commands\Ops\LoadTest\Support\RefusesProduction;
use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Throwable;

/**
 * Seeds N throwaway tenants for the load-test harness, each with one
 * assistant, one Telegram channel (pointed at the local stub), and one
 * published flow: prompt for a code → store the reply on the contact →
 * echo it back → end.
 *
 * Creating an active channel synchronously registers a provider webhook
 * ({@see \App\Domains\Channels\Observers\ChannelObserver} →
 * {@see \App\Jobs\Messaging\SyncChannelWebhookJob::dispatchSync}), which
 * calls the real Telegram origin unless `services.telegram.api_base_url`
 * points elsewhere — so this command refuses to run against the real API.
 */
final class LoadTestSeedCommand extends Command
{
    use RefusesProduction;

    /** @var string */
    protected $signature = 'loadtest:seed
        {--tenants=3 : Number of tenants to provision}
        {--contacts=100 : Total synthetic contacts to plan for, spread across tenants}';

    /** @var string */
    protected $description = 'Seed tenants/assistants/channels/flow for the load-test harness';

    public function __construct(
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly AssistantServiceInterface $assistants,
        private readonly ChannelServiceInterface $channels,
        private readonly CreateFlowAction $flows,
        private readonly PublishFlowService $publisher,
        private readonly Repository $config,
        private readonly LoadTestStateStore $state,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->refuseInProduction()) {
            return self::FAILURE;
        }

        $apiBaseUrl = mb_rtrim((string)$this->config->get('services.telegram.api_base_url', 'https://api.telegram.org'), '/');

        if ('https://api.telegram.org' === $apiBaseUrl) {
            $this->components->error(
                'services.telegram.api_base_url still points at the real Telegram API. '
                . 'Start tools/loadtest/telegram-stub.php and set TELEGRAM_API_BASE_URL to it before seeding.',
            );

            return self::FAILURE;
        }

        $tenantCount  = max(1, (int)$this->option('tenants'));
        $contactTotal = max(0, (int)$this->option('contacts'));
        $runId        = bin2hex(random_bytes(4));

        $this->components->info("Load-test run {$runId}: {$tenantCount} tenant(s), {$contactTotal} contact(s), stub at {$apiBaseUrl}");

        // Saved after every tenant, so a run that fails half-way stays cleanable
        // by `loadtest:clean`; a tenant that fails inside provisioning is not
        // recorded yet and is left for `loadtest:clean --all`.
        $state = [
            'run_id'        => $runId,
            'created_at'    => now()->toIso8601String(),
            'api_base_url'  => $apiBaseUrl,
            'stub_log_path' => $this->resolveStubLogPath(),
            'options'       => ['tenants' => $tenantCount, 'contacts' => $contactTotal],
            'complete'      => false,
            'tenants'       => [],
            'contacts'      => [],
        ];
        $this->state->save($state);

        for ($i = 1; $i <= $tenantCount; $i++) {
            $contactCount = intdiv($contactTotal, $tenantCount) + ($i <= $contactTotal % $tenantCount ? 1 : 0);

            try {
                [$tenantRow, $tenantContacts] = $this->seedTenant($runId, $i, $contactCount);
            } catch (Throwable $exception) {
                $this->components->error("Tenant #{$i} failed: {$exception->getMessage()}");
                $this->components->warn('Remove what was created with `php artisan loadtest:clean --all`.');

                return self::FAILURE;
            }

            $state['tenants'][] = $tenantRow;
            array_push($state['contacts'], ...$tenantContacts);
            $this->state->save($state);

            $this->components->twoColumnDetail($tenantRow['slug'], "{$contactCount} contact(s) planned");
        }

        $state['complete'] = true;
        $this->state->save($state);
        $contacts = $state['contacts'];

        $this->components->info("Seeded {$tenantCount} tenant(s) and planned " . count($contacts) . ' contact(s).');
        $this->components->twoColumnDetail('State file', $this->state->path());

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function seedTenant(string $runId, int $tenantIndex, int $contactCount): array
    {
        $slug = "loadtest-{$runId}-{$tenantIndex}";

        $tenant = $this->provisioning->provision(
            slug: $slug,
            firstAdminEmail: "loadtest+{$slug}@example.test",
            firstAdminPassword: Str::random(24),
            firstAdminName: 'Load Test Admin',
        );

        $botToken    = "{$runId}-{$tenantIndex}-" . bin2hex(random_bytes(8));
        $secretToken = bin2hex(random_bytes(16));

        /** @var array{assistant_id: string, flow_id: string, channel_id: string, channel_hash: string} $created */
        $created = $this->tenantSwitcher->runForTenant(
            $tenant,
            fn (): array => $this->createAssistantChannelAndFlow($tenant, $slug, $botToken, $secretToken),
        );

        $contacts = [];
        for ($j = 1; $j <= $contactCount; $j++) {
            $contacts[] = [
                'tenant_slug' => $slug,
                'chat_id'     => LoadTestCodeGenerator::chatId($tenantIndex, $j),
                'code'        => LoadTestCodeGenerator::code($slug, $j),
            ];
        }

        return [
            [
                'slug'         => $slug,
                'tenant_id'    => $tenant->getId(),
                'schema_name'  => $tenant->getSchemaName(),
                'assistant_id' => $created['assistant_id'],
                'flow_id'      => $created['flow_id'],
                'channel_id'   => $created['channel_id'],
                'channel_hash' => $created['channel_hash'],
                'secret_token' => $secretToken,
                'bot_token'    => $botToken,
            ],
            $contacts,
        ];
    }

    /**
     * Mirrors the stub script's own default so both sides agree without a
     * shared config file — the stub is a standalone process, not part of
     * this Laravel app.
     */
    private function resolveStubLogPath(): string
    {
        $fromEnv = getenv('LOADTEST_STUB_LOG');

        return false !== $fromEnv && '' !== $fromEnv ? $fromEnv : storage_path('logs/loadtest-stub.jsonl');
    }

    /**
     * @return array{assistant_id: string, flow_id: string, channel_id: string, channel_hash: string}
     */
    private function createAssistantChannelAndFlow(
        TenantInterface $tenant,
        string $slug,
        string $botToken,
        string $secretToken,
    ): array {
        $assistant = $this->assistants->create($tenant, ['name' => "Load Test {$slug}"]);

        $flowId = $this->flows->execute([
            'assistant_id' => (string)$assistant->getKey(),
            'name'         => 'Load Test Flow',
            'nodes'        => LoadTestFlowBlueprint::nodes(),
            'edges'        => LoadTestFlowBlueprint::edges(),
        ])->flow_id;

        $this->publisher->execute($flowId);

        $assistant = $this->assistants->update($assistant, ['default_flow_id' => $flowId]);

        // Active + synchronous: the ChannelObserver dispatches
        // SyncChannelWebhookJob::dispatchSync() right here, which calls
        // setWebhook/getMe against services.telegram.api_base_url (the stub).
        $channel = $this->channels->create($assistant, [
            'type'         => 'telegram',
            'token'        => $botToken,
            'secret_token' => $secretToken,
            'config'       => [],
            'is_active'    => true,
        ]);

        return [
            'assistant_id' => (string)$assistant->getKey(),
            'flow_id'      => $flowId,
            'channel_id'   => (string)$channel->getKey(),
            'channel_hash' => $channel->webhook_public_hash,
        ];
    }
}
