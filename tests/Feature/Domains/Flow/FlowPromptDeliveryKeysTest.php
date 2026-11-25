<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface as FlowMessageSenderInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Flow\SendOneTimeCodeJob;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The prompts and hints a waiting node sends go through the engine and the real FlowMessageSender, which keys every
 * outbound message by its node and the step's idempotency key. Only the outbound sender at the channel edge is a
 * recorder; like the real one, it delivers a key once, so two messages sharing a key would lose the second.
 */
final class FlowPromptDeliveryKeysTest extends FeatureTestCase
{
    private const string BUTTON_ONE = '11111111-1111-4111-8111-111111111111';

    private const string BUTTON_TWO = '22222222-2222-4222-8222-222222222222';

    private string $tenantId;

    private RecordingOutboundSender $outbound;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outbound = new RecordingOutboundSender();
        $this->app->instance(OutboundMessageSenderInterface::class, $this->outbound);
        // The scoped flow sender may already hold the real outbound sender (the first test of a process boots it).
        $this->app->forgetInstance(FlowMessageSenderInterface::class);
    }

    public function test_an_input_select_prompt_goes_out_with_its_keyboard_and_the_session_waits(): void
    {
        $contact    = $this->contactWithChannel();
        $definition = $this->definition([
            ['id' => 'pick', 'type' => 'input', 'version' => 1, 'config' => [
                'expected_type' => 'select',
                'prompt'        => ['en' => 'Pick one'],
                'variable'      => ['name' => 'choice', 'storage' => 'session'],
                'buttons'       => [
                    ['id' => self::BUTTON_ONE, 'value' => 'a', 'label' => ['en' => 'Alpha'], 'row' => 0, 'order' => 0],
                    ['id' => self::BUTTON_TWO, 'value' => 'b', 'label' => ['en' => 'Beta'], 'row' => 0, 'order' => 1],
                ],
            ]],
        ]);

        $session = $this->engine()->start($definition, $contact);

        $this->assertWaitingWithoutErrors($session, 'pick');
        $this->assertCount(1, $this->outbound->delivered);

        $message = $this->outbound->delivered[0];
        $this->assertSame('Pick one', $message->payload->text);
        $this->assertNotNull($message->payload->buttons);
        $this->assertStringStartsWith((string) $session->getKey() . ':pick:', $message->idempotencyKey);
        $this->assertSame('pick', $message->metadata['origin_ref']['node_id']);
    }

    public function test_an_input_prompt_and_its_invalid_hint_are_two_deliveries_under_two_keys(): void
    {
        $contact    = $this->contactWithChannel();
        $definition = $this->definition([
            ['id' => 'age', 'type' => 'input', 'version' => 1, 'config' => [
                'expected_type'      => 'number',
                'prompt'             => ['en' => 'Your age?'],
                'on_invalid_message' => ['en' => 'A number, please.'],
                'variable'           => ['name' => 'age', 'storage' => 'session'],
            ]],
        ]);

        $session = $this->engine()->start($definition, $contact);
        $this->assertWaitingWithoutErrors($session, 'age');

        $session = $this->engine()->resume($session, $this->incomingText('u-1', 'not a number'));
        $this->assertWaitingWithoutErrors($session, 'age');

        $this->assertSame(['Your age?', 'A number, please.'], array_map(
            static fn (OutboundMessage $message): ?string => $message->payload->text,
            $this->outbound->delivered,
        ));
        $this->assertCount(2, array_unique(array_map(
            static fn (OutboundMessage $message): string => $message->idempotencyKey,
            $this->outbound->delivered,
        )));
        // The hint keeps a key of its own under the node, whatever step sends it.
        $this->assertStringStartsWith((string) $session->getKey() . ':age:hint:', $this->outbound->delivered[1]->idempotencyKey);
    }

    public function test_an_input_contact_prompt_with_its_native_button_goes_out(): void
    {
        $contact    = $this->contactWithChannel();
        $definition = $this->definition([
            ['id' => 'share', 'type' => 'input', 'version' => 1, 'config' => [
                'expected_type'        => 'contact',
                'prompt'               => ['en' => 'Share your contact'],
                'request_button_label' => ['en' => 'Share'],
                'variable'             => ['name' => 'phone', 'storage' => 'session'],
            ]],
        ]);

        $session = $this->engine()->start($definition, $contact);

        $this->assertWaitingWithoutErrors($session, 'share');
        $this->assertCount(1, $this->outbound->delivered);
        $this->assertSame('Share your contact', $this->outbound->delivered[0]->payload->text);
    }

    public function test_an_auth_request_phone_prompt_and_its_repeat_are_two_deliveries(): void
    {
        $contact    = $this->contactWithChannel();
        $definition = $this->definition([
            ['id' => 'auth', 'type' => 'auth_request', 'version' => 2, 'config' => [
                'method'             => 'phone',
                'prompt'             => 'Share your phone number.',
                'button_label'       => 'Share phone',
                'on_invalid_message' => 'Use the button, please.',
            ]],
        ]);

        $session = $this->engine()->start($definition, $contact);
        $this->assertWaitingWithoutErrors($session, 'auth');

        $session = $this->engine()->resume($session, $this->incomingText('u-1', '+380000000000'));
        $this->assertWaitingWithoutErrors($session, 'auth');

        $this->assertSame(['Share your phone number.', 'Use the button, please.'], array_map(
            static fn (OutboundMessage $message): ?string => $message->payload->text,
            $this->outbound->delivered,
        ));
        $this->assertCount(2, array_unique(array_map(
            static fn (OutboundMessage $message): string => $message->idempotencyKey,
            $this->outbound->delivered,
        )));
    }

    public function test_an_auth_request_code_prompt_with_its_resend_button_and_the_wrong_code_hint_are_two_deliveries(): void
    {
        config(['mail.default' => 'smtp']);
        Bus::fake([SendOneTimeCodeJob::class]);

        $tenant = Tenant::query()->firstOrFail();
        $this->app->make(TenantContextInterface::class)->set($tenant);
        $this->tenantId = (string) $tenant->getKey();

        $contact    = $this->contactWithChannel();
        $definition = $this->definition([
            ['id' => 'auth', 'type' => 'auth_request', 'version' => 2, 'config' => [
                'method'             => 'email_code',
                'prompt'             => 'Type the code we emailed you.',
                'resend_label'       => 'New code',
                'destination'        => 'client@example.com',
                'on_invalid_message' => 'That code is wrong.',
            ]],
        ]);

        $session = $this->engine()->start($definition, $contact);
        $this->assertWaitingWithoutErrors($session, 'auth');

        $session = $this->engine()->resume($session, $this->incomingText('u-1', '000'));
        $this->assertWaitingWithoutErrors($session, 'auth');

        $this->assertSame(['Type the code we emailed you.', 'That code is wrong.'], array_map(
            static fn (OutboundMessage $message): ?string => $message->payload->text,
            $this->outbound->delivered,
        ));
        $this->assertNotNull($this->outbound->delivered[0]->payload->buttons);
        $this->assertCount(2, array_unique(array_map(
            static fn (OutboundMessage $message): string => $message->idempotencyKey,
            $this->outbound->delivered,
        )));
        $this->assertStringStartsWith((string) $session->getKey() . ':auth:hint:', $this->outbound->delivered[1]->idempotencyKey);
    }

    private function assertWaitingWithoutErrors(FlowSession $session, string $nodeId): void
    {
        $errors = DB::table('flow_logs')->where('session_id', $session->getKey())->whereNotNull('error')->pluck('error')->all();

        $this->assertSame([], $errors);
        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertSame($nodeId, $session->current_node_id);
    }

    private function engine(): FlowEngineInterface
    {
        return $this->app->make(FlowEngineInterface::class);
    }

    private function incomingText(string $updateId, string $text): IncomingMessage
    {
        return new IncomingMessage(
            updateId: $updateId,
            externalUserId: 'ext-user',
            externalChatId: 'ext-chat',
            text: $text,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function definition(array $nodes): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Prompt keys',
            'nodes'     => $nodes,
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    private function contactWithChannel(): Contact
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $contact = Contact::factory()->forTenant($this->tenantId)->create(['external_id' => 'chat-prompt']);

        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $this->tenantId,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        return $contact;
    }
}

/**
 * Delivers each idempotency key once, as MessageSender does: a repeated key is reported as a duplicate and not
 * delivered again.
 */
final class RecordingOutboundSender implements OutboundMessageSenderInterface
{
    /** @var list<OutboundMessage> */
    public array $delivered = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (isset($this->seen[$message->idempotencyKey])) {
            return new DeliveryResult(sent: true, duplicate: true);
        }

        $this->seen[$message->idempotencyKey] = true;
        $this->delivered[]                    = $message;

        return new DeliveryResult(sent: true, providerMessageId: 'msg-' . count($this->delivered));
    }
}
