<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Webhook;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Webhook\FlowWebhookEndpointService;
use App\Domains\Flow\Webhook\FlowWebhookReceiver;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Jobs\Flow\StartAutomationRunJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * In `host` mode the tenant is the host's: the base domain names none, and one tenant's webhook URL leads nowhere on
 * another tenant's host.
 */
final class FlowWebhookHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    private const string TENANT_A = '00000000-0000-0000-0000-000000000001';

    private const string SECRET = 'a-shared-secret-of-enough-length';

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionSecondTenant();
        $this->base = (string) config('tenancy.base_domain');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreTenancyResolution();
    }

    public function test_the_base_domain_has_no_tenant_and_no_webhook(): void
    {
        $this->postWebhook("http://{$this->base}", str_repeat('A', 43), '{}')->assertNotFound();
    }

    public function test_a_tenants_webhook_url_leads_nowhere_on_another_tenants_host(): void
    {
        $token = $this->liveWebhookOfTenantA();

        $this->postWebhook("http://second.{$this->base}", $token, '{}')
            ->assertNotFound()
            ->assertExactJson(['status' => 'rejected']);
        Queue::assertNothingPushed();

        $this->postWebhook("http://main.{$this->base}", $token, '{}')->assertStatus(202);
        Queue::assertPushed(StartAutomationRunJob::class, fn (StartAutomationRunJob $job): bool => self::TENANT_A === $job->tenantId);
    }

    public function test_dead_url_misses_on_one_tenants_host_do_not_hold_back_another_tenants_live_url_from_the_same_address(): void
    {
        $token = $this->liveWebhookOfTenantA();

        for ($i = 0; $i < FlowWebhookReceiver::UNKNOWN_TOKENS_PER_MINUTE; ++$i) {
            $this->postWebhook("http://second.{$this->base}", mb_str_pad((string) $i, 43, 'B'), '{}')->assertNotFound();
        }

        $this->postWebhook("http://second.{$this->base}", str_repeat('C', 43), '{}')->assertStatus(429);
        $this->postWebhook("http://main.{$this->base}", $token, '{}')->assertStatus(202);
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    private function liveWebhookOfTenantA(): string
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->getById(self::TENANT_A);

        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, function (): string {
            $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_A, 'default_language' => 'en']);
            $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
            $draft     = FlowDraft::factory()->create([
                'tenant_id'    => self::TENANT_A,
                'assistant_id' => $assistant->getKey(),
                'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
                'edges'        => [],
                'triggers'     => [[
                    'id'        => $triggerId,
                    'type'      => 'webhook',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => ['verification' => 'header_equals', 'signature_header' => 'X-Webhook-Secret', 'response_mode' => 'async'],
                ]],
            ]);

            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
            $endpoints = $this->app->make(FlowWebhookEndpointService::class);
            $endpoints->setSecret($draft, $triggerId, self::SECRET);

            return $endpoints->ensure($draft, $triggerId)->token;
        });
    }

    private function postWebhook(string $origin, string $token, string $body): TestResponse
    {
        return $this->call('POST', "{$origin}/hooks/flow/{$token}", [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X_WEBHOOK_SECRET' => self::SECRET,
        ], $body);
    }
}
