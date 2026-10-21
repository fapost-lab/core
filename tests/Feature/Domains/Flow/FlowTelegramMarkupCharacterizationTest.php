<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface as FlowSenderContract;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowMessageSender;
use App\Domains\Flow\State\Variables\VariableResolver;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Flow\Validation\InputValidator;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Messaging\MessageSender;
use App\Domains\Messaging\TenantMessagingRateLimit;
use Closure;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;
use Tests\Support\UsageGates;

/**
 * Characterization of the Bot API `reply_markup` a flow message produces, end to end: the real
 * FlowMessageSender, the real MessageSender and the real Telegram sender, with only the HTTP and
 * Redis edges faked. Written against the code before the buttons became channel-neutral and kept
 * unchanged after, it proves the user's Telegram chat looks the same.
 */
final class FlowTelegramMarkupCharacterizationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-0000000000c1';

    private const string BUTTON_ONE = '11111111-1111-4111-8111-111111111111';

    private const string BUTTON_TWO = '22222222-2222-4222-8222-222222222222';

    private const string BUTTON_THREE = '33333333-3333-4333-8333-333333333333';

    private Assistant $assistant;

    private Contact $contact;

    private FlowSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]], 200),
        ]);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-char']);

        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $this->contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Characterization Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $this->contact->getKey(),
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);
    }

    public function test_inline_keyboard_markup(): void
    {
        $this->sendKeyboard('inline', [
            ['id' => self::BUTTON_THREE, 'label' => 'Maybe', 'row' => 1, 'order' => 0],
            ['id' => self::BUTTON_TWO, 'label' => 'No', 'row' => 0, 'order' => 1],
            ['id' => self::BUTTON_ONE, 'label' => 'Yes', 'row' => 0, 'order' => 0],
        ]);

        $sessionId = (string) $this->session->getKey();

        $this->assertSame([
            'inline_keyboard' => [
                [
                    ['text' => 'Yes', 'callback_data' => CallbackDataCodec::encode($sessionId, self::BUTTON_ONE)],
                    ['text' => 'No', 'callback_data' => CallbackDataCodec::encode($sessionId, self::BUTTON_TWO)],
                ],
                [
                    ['text' => 'Maybe', 'callback_data' => CallbackDataCodec::encode($sessionId, self::BUTTON_THREE)],
                ],
            ],
        ], $this->sentBody('sendMessage')['reply_markup']);
    }

    public function test_reply_keyboard_markup(): void
    {
        $this->sendKeyboard('reply', [
            ['id' => self::BUTTON_TWO, 'label' => 'Two', 'row' => 0, 'order' => 1],
            ['id' => self::BUTTON_ONE, 'label' => 'One', 'row' => 0, 'order' => 0],
            ['id' => self::BUTTON_THREE, 'label' => 'Three', 'row' => 1, 'order' => 0],
        ]);

        $this->assertSame([
            'keyboard' => [
                [['text' => 'One'], ['text' => 'Two']],
                [['text' => 'Three']],
            ],
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ], $this->sentBody('sendMessage')['reply_markup']);
    }

    public function test_contact_request_markup(): void
    {
        $this->sendKeyboard('reply', [
            ['id' => 'native-button', 'type' => 'reply', 'label' => 'Share contact', 'special' => 'request_contact', 'row' => 0, 'order' => 0],
        ]);

        $this->assertSame([
            'keyboard'          => [[['text' => 'Share contact', 'request_contact' => true]]],
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ], $this->sentBody('sendMessage')['reply_markup']);
    }

    public function test_location_request_markup(): void
    {
        $this->sendKeyboard('reply', [
            ['id' => 'native-button', 'type' => 'reply', 'label' => 'Share location', 'special' => 'request_location', 'row' => 0, 'order' => 0],
        ]);

        $this->assertSame([
            'keyboard'          => [[['text' => 'Share location', 'request_location' => true]]],
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ], $this->sentBody('sendMessage')['reply_markup']);
    }

    public function test_input_select_prompt_markup(): void
    {
        $sessionId  = (string) $this->session->getKey();
        $flowSender = $this->flowSender();
        $sender     = new class ($flowSender) implements FlowSenderContract {
            public function __construct(private readonly FlowMessageSender $inner)
            {
            }

            public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
            {
                return $this->inner->send($tenantId, $contactId, $sessionId, $payload + [
                    'node_id'         => 'input-select',
                    'idempotency_key' => 'idem-select',
                ]);
            }
        };

        $translator = Mockery::mock(ContentTranslatorInterface::class);
        $translator->shouldReceive('resolveField')->andReturnUsing(
            static fn (mixed $field): string => is_array($field) ? (string) ($field['en'] ?? reset($field)) : (string) $field,
        );

        $handler = new InputNodeHandler(
            Mockery::mock(MediaIngestorInterface::class),
            Mockery::mock(MediaServiceInterface::class),
            new VariableResolver(),
            $sender,
            $translator,
            new InputValidator(),
        );

        $handler->execute([
            'id'     => 'input-select',
            'config' => [
                'expected_type' => 'select',
                'prompt'        => ['en' => 'Pick one'],
                'variable'      => ['name' => 'choice', 'storage' => 'session'],
                'buttons'       => [
                    ['id' => self::BUTTON_ONE, 'value' => 'a', 'label' => ['en' => 'Alpha', 'de' => 'Alfa'], 'row' => 0, 'order' => 0],
                    ['id' => self::BUTTON_TWO, 'value' => 'b', 'label' => ['en' => 'Beta'], 'row' => 0, 'order' => 1],
                    ['id' => self::BUTTON_THREE, 'value' => 'c', 'label' => 'Gamma', 'row' => 1, 'order' => 0],
                ],
            ],
        ], [], new NodeExecutionContext(
            tenantId: self::TENANT_ID,
            contactId: (string) $this->contact->getKey(),
            sessionId: $sessionId,
            nodeId: 'input-select',
            idempotencyKey: 'idem-select',
            platform: 'telegram',
        ));

        $body = $this->sentBody('sendMessage');

        $this->assertSame('Pick one', $body['text']);
        $this->assertSame([
            'inline_keyboard' => [
                [
                    ['text' => 'Alpha', 'callback_data' => CallbackDataCodec::encode($sessionId, self::BUTTON_ONE)],
                    ['text' => 'Beta', 'callback_data' => CallbackDataCodec::encode($sessionId, self::BUTTON_TWO)],
                ],
                [
                    ['text' => 'Gamma', 'callback_data' => CallbackDataCodec::encode($sessionId, self::BUTTON_THREE)],
                ],
            ],
        ], $body['reply_markup']);
    }

    public function test_a_keyboard_message_without_buttons_goes_out_as_plain_text(): void
    {
        $this->sendKeyboard('inline', []);

        $this->assertArrayNotHasKey('reply_markup', $this->sentBody('sendMessage'));
    }

    public function test_a_button_with_an_empty_label_is_refused_before_delivery(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->sendKeyboard('inline', [
                ['id' => self::BUTTON_ONE, 'label' => '', 'row' => 0, 'order' => 0],
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_callback_payload_over_the_limit_is_refused_before_delivery(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->sendKeyboard('inline', [
                ['id' => str_repeat('a', 40), 'label' => 'Too long', 'row' => 0, 'order' => 0],
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_removing_the_keyboard_edits_the_reply_markup_of_the_sent_message(): void
    {
        $this->app->instance(OutboundMessageSenderInterface::class, $this->messageSender());

        $this->app->make(InlineKeyboardEditorInterface::class)->removeKeyboard(
            self::TENANT_ID,
            (string) $this->contact->getKey(),
            (string) $this->session->getKey(),
            '4242',
        );

        $body = $this->sentBody('editMessageReplyMarkup');

        $this->assertSame(['inline_keyboard' => []], $body['reply_markup']);
        $this->assertSame(4242, $body['message_id']);
        $this->assertSame('chat-char', $body['chat_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $buttons
     */
    private function sendKeyboard(string $mode, array $buttons): void
    {
        $sessionId = (string) $this->session->getKey();

        $this->flowSender()->send(self::TENANT_ID, (string) $this->contact->getKey(), $sessionId, [
            'node_id'         => 'node-1',
            'idempotency_key' => 'idem-' . $mode . '-' . Str::random(6),
            'session_id'      => $sessionId,
            'content_type'    => 'text_with_keyboard',
            'keyboard_mode'   => $mode,
            'text'            => 'Choose',
            'buttons'         => $buttons,
        ]);
    }

    private function flowSender(): FlowMessageSender
    {
        return new FlowMessageSender(
            $this->messageSender(),
            Mockery::mock(MediaDispatcherInterface::class),
            $this->mock(ConversationLoggerInterface::class, function (MockInterface $mock): void {
                $mock->shouldReceive('log');
            }),
            new ConversationCaptureFactory(),
            UsageGates::gate(),
        );
    }

    /**
     * The real pipeline (idempotency, rate limit, registry, Telegram sender); Redis is an in-memory stub.
     */
    private function messageSender(): MessageSender
    {
        $connection = $this->mock(Connection::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->andReturn(true);
            $mock->shouldReceive('del');
            $mock->shouldReceive('pipeline')->andReturnUsing(function (Closure $callback): array {
                $pipe = Mockery::mock();
                $pipe->shouldReceive('incr');
                $pipe->shouldReceive('expire');
                $callback($pipe);

                return [1, true];
            });
        });

        $redis = $this->mock(RedisFactory::class, function (MockInterface $mock) use ($connection): void {
            $mock->shouldReceive('connection')->andReturn($connection);
        });

        return new MessageSender(
            $this->app->make(ChannelRegistryInterface::class),
            $redis,
            $this->app->make(TenantMessagingRateLimit::class),
            $this->mock(ConversationLoggerInterface::class, function (MockInterface $mock): void {
                $mock->shouldReceive('log');
            }),
            new ConversationCaptureFactory(),
            UsageGates::gate(),
        );
    }

    /**
     * @return array<string, mixed> the decoded JSON body of the single request to the Bot API method
     */
    private function sentBody(string $method): array
    {
        $bodies = [];

        Http::assertSent(function (Request $request) use ($method, &$bodies): bool {
            if ("https://api.telegram.org/botbot-token/{$method}" === (string) $request->url()) {
                $bodies[] = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            }

            return true;
        });

        $this->assertCount(1, $bodies);

        return $bodies[0];
    }
}
