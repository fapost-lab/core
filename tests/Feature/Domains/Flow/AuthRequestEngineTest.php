<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Flow\SendOneTimeCodeJob;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * `auth_request@2` run by the engine: the session waits for the code, resumes on it, takes the `authenticated` edge,
 * and the contact is flagged.
 */
final class AuthRequestEngineTest extends FeatureTestCase
{
    public function test_an_email_code_typed_back_authenticates_the_contact_through_the_engine(): void
    {
        config(['mail.default' => 'smtp']);
        Bus::fake([SendOneTimeCodeJob::class]);
        $this->app->instance(MessageSenderInterface::class, new class () implements MessageSenderInterface {
            public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
            {
                return 'msg-' . Str::random(6);
            }
        });

        $tenant = Tenant::query()->firstOrFail();
        $this->app->make(TenantContextInterface::class)->set($tenant);
        $tenantId  = (string) $tenant->getKey();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);
        $contact = Contact::factory()->forTenant($tenantId)->create();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Auth',
            'nodes'     => [
                ['id' => 'auth', 'type' => 'auth_request', 'version' => 2, 'config' => [
                    'method'      => 'email_code',
                    'prompt'      => 'Type the code we emailed you.',
                    'destination' => 'client@example.com',
                ]],
                ['id' => 'yes', 'type' => 'assign', 'version' => 1, 'config' => ['target' => 'flow', 'key' => 'result', 'value' => 'in']],
                ['id' => 'no', 'type' => 'assign', 'version' => 1, 'config' => ['target' => 'flow', 'key' => 'result', 'value' => 'out']],
            ],
            'edges' => [
                ['from' => 'auth', 'to' => 'yes', 'handle' => 'authenticated'],
                ['from' => 'auth', 'to' => 'no', 'handle' => 'failed'],
            ],
            'is_active' => true,
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame('auth', $session->current_node_id);
        $this->assertTrue($session->state['system']['auth']['auth']['active']);

        $code = Bus::dispatched(SendOneTimeCodeJob::class)->first()->code;
        $this->assertStringNotContainsString($code, (string) json_encode($session->state));

        $session = $engine->resume($session, new IncomingMessage(
            updateId: 'u1',
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: $code,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        ));

        $this->assertSame('in', $session->fresh()->state['flow']['result'] ?? null);
        $this->assertSame(['challenge' => 1], $session->fresh()->state['system']['auth']['auth']);
        $this->assertTrue((bool) $contact->fresh()->is_authenticated);
    }
}
