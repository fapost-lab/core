<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Auth\AuthCodeStore;
use App\Domains\Flow\Auth\OneTimeCodeSenderRegistry;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Handlers\AuthRequestV2NodeHandler;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\Handlers\Support\OperatorComparator;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Readers\ScopedStateReader;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Tenancy\Services\LimitAnnouncer;
use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\UnlimitedUsageMeter;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Flow\SendOneTimeCodeJob;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderInterface;
use Fapost\Foundation\Auth\DTO\OneTimeCodeDelivery;
use Fapost\Foundation\Auth\DTO\OneTimeCodeMessage;
use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\ContactWriterInterface;
use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Testing\Fakes\BusFake;
use Tests\Support\FakeUsageMeter;
use Tests\TestCase;

/**
 * `auth_request@2`: the ownership checks, their limits, and that the code never reaches what the engine writes.
 */
final class AuthRequestV2NodeHandlerTest extends TestCase
{
    private const string TENANT = '01k00000000000000000000000';

    private const string SESSION = '0190a3b2-1c4d-7e5f-8a9b-0c1d2e3f4a5b';

    private const string NODE = 'auth-1';

    private const string USER = '777000';


    private Repository $cache;

    private BusFake $bus;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<array{0: string, 1: mixed}> */
    private array $written = [];

    /** @var list<NodeExecutionResult> */
    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Repository(new ArrayStore());
        $this->bus   = Bus::fake();
    }

    public function test_basic_matches_through_authenticated_and_misses_through_failed(): void
    {
        $handler = $this->handler();
        $config  = ['method' => 'basic', 'variable' => 'flow.code', 'operator' => 'eq', 'value' => '1234'];

        $pass = $handler->execute($this->node($config), ['flow' => ['code' => '1234']], $this->context());
        $miss = $handler->execute($this->node($config), ['flow' => ['code' => '0000']], $this->context());

        $this->assertSame('authenticated', $pass->sourceHandle);
        $this->assertSame('failed', $miss->sourceHandle);
        $this->assertSame([['contact.is_authenticated', true]], $this->written);
    }

    public function test_phone_asks_with_a_share_button_then_accepts_the_contacts_own_number(): void
    {
        $handler = $this->handler();
        $config  = ['method' => 'phone', 'prompt' => 'Share your number', 'button_label' => 'Share'];

        $first = $handler->execute($this->node($config), [], $this->context());

        $this->assertSame(NodeExecutionStatus::Waiting, $first->status);
        $this->assertSame('request_contact', $this->sent[0]['buttons'][0]['special']);

        $result = $handler->execute($this->node($config), $this->apply([], $first), $this->context($this->contactCard('380501234567', self::USER)));

        $this->assertSame('authenticated', $result->sourceHandle);
        $this->assertSame('+38050***67', $result->metadata['destination_masked']);
        $this->assertSame([['contact.is_authenticated', true]], $this->written);
        $this->assertSame(['challenge' => 1], $result->stateChanges['system.auth.' . self::NODE]);
    }

    public function test_phone_refuses_a_forwarded_card_and_text_and_fails_after_its_attempts(): void
    {
        $handler = $this->handler();
        $config  = ['method' => 'phone', 'prompt' => 'Share your number', 'max_attempts' => 2];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));

        $foreign = $handler->execute($this->node($config), $state, $this->context($this->contactCard('380501234567', '999')));

        $this->assertSame(NodeExecutionStatus::Waiting, $foreign->status);
        $this->assertSame('foreign_contact', $foreign->metadata['reason']);

        $text = $handler->execute($this->node($config), $this->apply($state, $foreign), $this->context($this->text('+380501234567')));

        $this->assertSame('failed', $text->sourceHandle);
        $this->assertSame('attempts_exhausted', $text->metadata['reason']);
        $this->assertSame([], $this->written);
    }

    public function test_phone_with_an_expected_number_accepts_only_that_number(): void
    {
        $handler = $this->handler();
        $config  = ['method' => 'phone', 'prompt' => 'Share', 'expected_phone' => '{{flow.crm_phone}}'];
        $state   = $this->apply(['flow' => ['crm_phone' => '+38 (050) 123-45-67']], $handler->execute($this->node($config), [], $this->context()));

        $other = $handler->execute($this->node($config), $state, $this->context($this->contactCard('380509999999', self::USER)));
        $this->assertSame('phone_mismatch', $other->metadata['reason']);
        $this->assertSame([], $this->written);

        $same = $handler->execute($this->node($config), $this->apply($state, $other), $this->context($this->contactCard('380501234567', self::USER)));
        $this->assertSame('authenticated', $same->sourceHandle);
    }

    public function test_an_email_code_is_sent_once_and_the_right_code_authenticates(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig();

        $first = $handler->execute($this->node($config), [], $this->context());

        $this->assertSame(NodeExecutionStatus::Waiting, $first->status);
        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 1);
        $job = $this->job();
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertSame('client@example.com', $job->destination);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $job->code);
        $this->assertSame('c***@example.com', $first->metadata['destination_masked']);
        $this->assertSame(AuthRequestV2NodeHandler::resendButtonId(self::NODE), $this->sent[0]['buttons'][0]['id']);

        $state  = $this->apply([], $first);
        $result = $handler->execute($this->node($config), $state, $this->context($this->text(' ' . $job->code . ' ')));

        $this->assertSame('authenticated', $result->sourceHandle);
        $this->assertSame([['contact.is_authenticated', true]], $this->written);

        // The step retried before its state was saved still authenticates.
        $retried = $handler->execute($this->node($config), $state, $this->context($this->text($job->code)));
        $this->assertSame('authenticated', $retried->sourceHandle);

        // Entering the node again is a new challenge: the used code is not accepted by it.
        $next   = $this->apply($this->apply($state, $result), $handler->execute($this->node($config), $this->apply($state, $result), $this->context()));
        $reused = $handler->execute($this->node($config), $next, $this->context($this->text($job->code)));
        $this->assertNotSame('authenticated', $reused->sourceHandle);
    }

    public function test_the_code_reaches_neither_state_nor_metadata_nor_the_prompt(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig() + ['max_attempts' => 2];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));
        $code    = $this->job()->code;

        $state           = $this->apply($state, $handler->execute($this->node($config), $state, $this->context($this->text('000000' === $code ? '111111' : '000000'))));
        $this->results[] = $handler->execute($this->node($config), $state, $this->context($this->text($code)));

        $written = json_encode([
            array_map(static fn (NodeExecutionResult $r): array => [$r->stateChanges, $r->metadata, $r->logResolved, $r->trace], $this->results),
            $this->sent,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($code, $written);
        $this->assertStringNotContainsString(hash('sha256', $code), $written);
    }

    public function test_wrong_codes_use_up_the_attempts_and_the_code_is_dropped(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig() + ['max_attempts' => 2, 'on_invalid_message' => 'Wrong code'];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));
        $code    = $this->job()->code;
        $wrong   = '000000' === $code ? '111111' : '000000';

        $first = $handler->execute($this->node($config), $state, $this->context($this->text($wrong)));
        $this->assertSame(NodeExecutionStatus::Waiting, $first->status);
        $this->assertSame('Wrong code', end($this->sent)['text']);

        $state  = $this->apply($state, $first);
        $second = $handler->execute($this->node($config), $state, $this->context($this->text($wrong)));

        $this->assertSame('failed', $second->sourceHandle);
        $this->assertSame('attempts_exhausted', $second->metadata['reason']);
        $this->assertSame(2, $second->metadata['attempts']);
        $this->assertSame([], $this->written);

        // The right code no longer works once the attempts are used up.
        $late = $handler->execute($this->node($config), $state, $this->context($this->text($code)));
        $this->assertSame('expired', $late->metadata['reason']);
    }

    public function test_an_expired_code_fails(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig() + ['ttl_minutes' => 1];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));
        $code    = $this->job()->code;

        $this->travel(61)->seconds();

        $result = $handler->execute($this->node($config), $state, $this->context($this->text($code)));

        $this->assertSame('failed', $result->sourceHandle);
        $this->assertSame('expired', $result->metadata['reason']);
    }

    public function test_a_resend_waits_for_the_cooldown_replaces_the_code_and_stops_at_max_sends(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig() + ['max_sends' => 2, 'resend_cooldown_seconds' => 60];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));
        $old     = $this->job()->code;

        $early = $handler->execute($this->node($config), $state, $this->context($this->resendPress()));
        $this->assertSame('cooldown', $early->metadata['reason']);
        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 1);

        $this->travel(61)->seconds();
        $state = $this->apply($state, $handler->execute($this->node($config), $state, $this->context($this->resendPress())));
        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 2);
        $new = $this->job(1);
        $this->assertSame('1:2', $new->sendToken);
        // The last send offers no further resend button.
        $this->assertArrayNotHasKey('buttons', end($this->sent));

        if ($old !== $new->code) {
            $stale = $handler->execute($this->node($config), $state, $this->context($this->text($old)));
            $this->assertSame('wrong_code', $stale->metadata['reason']);
            $state = $this->apply($state, $stale);
        }

        $this->travel(61)->seconds();
        $over = $handler->execute($this->node($config), $state, $this->context($this->resendPress()));
        $this->assertSame('failed', $over->sourceHandle);
        $this->assertSame('rate_limited', $over->metadata['reason']);
        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 2);
    }

    public function test_a_retried_step_keeps_the_code_already_on_its_way(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig();

        $first = $handler->execute($this->node($config), [], $this->context());
        // The step is retried before its state was saved: the marker is not there yet.
        $handler->execute($this->node($config), [], $this->context());

        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 1);

        $result = $handler->execute($this->node($config), $this->apply([], $first), $this->context($this->text($this->job()->code)));
        $this->assertSame('authenticated', $result->sourceHandle);
    }

    public function test_one_address_gets_at_most_five_codes_an_hour(): void
    {
        $config = $this->emailConfig();

        for ($i = 0; $i < AuthCodeStore::DESTINATION_LIMIT; $i++) {
            $this->handler()->execute($this->node($config), [], $this->context(sessionId: sprintf('0190a3b2-1c4d-7e5f-8a9b-0c1d2e3f4a%02d', $i)));
        }

        $result = $this->handler()->execute($this->node($config), [], $this->context(sessionId: '0190a3b2-1c4d-7e5f-8a9b-0c1d2e3f4aff'));

        $this->assertSame('failed', $result->sourceHandle);
        $this->assertSame('rate_limited', $result->metadata['reason']);
        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, AuthCodeStore::DESTINATION_LIMIT);
    }

    public function test_a_used_up_quota_sends_nothing(): void
    {
        $result = $this->handler(FakeUsageMeter::denying())->execute($this->node($this->emailConfig()), [], $this->context());

        $this->assertSame('failed', $result->sourceHandle);
        $this->assertSame('rate_limited', $result->metadata['reason']);
        $this->bus->assertNotDispatched(SendOneTimeCodeJob::class);
    }

    public function test_an_sms_code_without_a_sender_fails_at_runtime_and_is_refused_at_publish(): void
    {
        $handler = $this->handler();
        $config  = ['method' => 'sms_code', 'prompt' => 'Type the code', 'destination' => '+380501234567'];

        $result = $handler->execute($this->node($config), [], $this->context());

        $this->assertSame('no_sender', $result->metadata['reason']);
        $this->bus->assertNotDispatched(SendOneTimeCodeJob::class);
        $this->assertContains('auth_sender_missing', $this->codes($handler->validate($this->node($config))));
    }

    public function test_publish_checks_the_prompt_the_address_and_the_bounds_and_warns_about_flow_addresses(): void
    {
        $handler = $this->handler();

        $this->assertContains('auth_prompt_required', $this->codes($handler->validate($this->node(['method' => 'phone']))));
        $this->assertContains('auth_destination_required', $this->codes($handler->validate($this->node(['method' => 'email_code', 'prompt' => 'Code?']))));
        $this->assertContains('auth_limit_out_of_range', $this->codes($handler->validate($this->node($this->emailConfig() + ['code_length' => 3]))));
        $this->assertSame([], $handler->validate($this->node($this->emailConfig())));

        $issues = $handler->validate($this->node(['destination' => '{{flow.email}}'] + $this->emailConfig()));
        $this->assertSame(['auth_destination_from_flow'], $this->codes($issues));
        $this->assertSame('warning', $issues[0]->severity->value);
    }

    public function test_a_failed_delivery_ends_the_challenge(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig();
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));

        $this->store()->markDeliveryFailed(self::TENANT, self::SESSION, self::NODE, '1:1');

        $result = $handler->execute($this->node($config), $state, $this->context($this->text('123456')));

        $this->assertSame('failed', $result->sourceHandle);
        $this->assertSame('delivery_failed', $result->metadata['reason']);
    }

    public function test_a_node_added_from_the_palette_starts_with_the_basic_method(): void
    {
        $this->assertSame(['method' => 'basic'], $this->handler()->configSchema()['default_config']);
    }

    public function test_entering_the_node_again_after_it_failed_sends_a_code_of_a_new_challenge(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig() + ['max_attempts' => 1];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));
        $wrong   = '000000' === $this->job()->code ? '111111' : '000000';
        $state   = $this->apply($state, $handler->execute($this->node($config), $state, $this->context($this->text($wrong))));

        // A failed edge leads back to the same node: a new challenge, a job of its own.
        $state = $this->apply($state, $handler->execute($this->node($config), $state, $this->context()));

        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 2);
        $this->assertSame('1:1', $this->job()->sendToken);
        $this->assertSame('2:1', $this->job(1)->sendToken);
        $this->assertNotSame($this->job()->idempotencyKey(), $this->job(1)->idempotencyKey());

        $result = $handler->execute($this->node($config), $state, $this->context($this->text($this->job(1)->code)));
        $this->assertSame('authenticated', $result->sourceHandle);
    }

    public function test_an_expected_phone_that_renders_empty_admits_nobody(): void
    {
        $handler = $this->handler();
        $config  = ['method' => 'phone', 'prompt' => 'Share', 'button_label' => 'Share', 'expected_phone' => '{{flow.crm_phone}}', 'max_attempts' => 1];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));

        $result = $handler->execute($this->node($config), $state, $this->context($this->contactCard('380501234567', self::USER)));

        $this->assertSame('failed', $result->sourceHandle);
        $this->assertSame('phone_mismatch', $result->metadata['detail']);
        $this->assertSame([], $this->written);
    }

    public function test_a_message_that_is_not_text_costs_no_attempt(): void
    {
        $handler = $this->handler();
        $config  = $this->emailConfig() + ['max_attempts' => 1];
        $state   = $this->apply([], $handler->execute($this->node($config), [], $this->context()));

        $photo = $handler->execute($this->node($config), $state, $this->context(
            new IncomingMessage('u4', self::USER, self::USER, null, IncomingMessageType::Photo, 'telegram'),
        ));

        $this->assertSame(NodeExecutionStatus::Waiting, $photo->status);
        $this->assertSame([], $photo->stateChanges);

        $result = $handler->execute($this->node($config), $state, $this->context($this->text($this->job()->code)));
        $this->assertSame('authenticated', $result->sourceHandle);
    }

    public function test_a_retried_step_spends_no_second_destination_slot(): void
    {
        $config = $this->emailConfig();

        for ($i = 0; $i < AuthCodeStore::DESTINATION_LIMIT; $i++) {
            $this->handler()->execute($this->node($config), [], $this->context());
        }

        $this->bus->assertDispatchedTimes(SendOneTimeCodeJob::class, 1);
        $other = $this->handler()->execute($this->node($config), [], $this->context(sessionId: '0190a3b2-1c4d-7e5f-8a9b-0c1d2e3f4aee'));
        $this->assertSame(NodeExecutionStatus::Waiting, $other->status);
    }

    public function test_the_example_address_of_the_builder_hint_resolves_in_every_locale(): void
    {
        $contact = new Contact(['attributes' => ['email' => 'client@example.com']]);
        $reader  = new ScopedStateReader([], $contact, app(DataAccessorRegistryInterface::class), app(StateNamespacesInterface::class));

        foreach (['en', 'ru', 'uk'] as $locale) {
            $hint = trans('builder.nodes.auth_request.destination_hint_email', [], $locale);

            $this->assertSame(1, preg_match('/\{\{\s*([\w.]+)\s*\}\}/', $hint, $match), $locale);
            $this->assertSame('client@example.com', $reader->read($match[1]), $locale);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emailConfig(): array
    {
        return [
            'method'       => 'email_code',
            'prompt'       => 'We sent you a code. Type it here.',
            'destination'  => 'Client@Example.com',
            'resend_label' => 'Send a new code',
        ];
    }

    private function handler(?UsageMeterInterface $meter = null): AuthRequestV2NodeHandler
    {
        $registry = new OneTimeCodeSenderRegistry();
        $registry->setDefault(OneTimeCodeChannel::Email, static fn (): OneTimeCodeSenderInterface => new class () implements OneTimeCodeSenderInterface {
            public function channel(): OneTimeCodeChannel
            {
                return OneTimeCodeChannel::Email;
            }

            public function send(OneTimeCodeMessage $message): OneTimeCodeDelivery
            {
                return OneTimeCodeDelivery::sent();
            }
        });

        $limits = new LimitRegistry();
        $limits->register(new LimitDefinition(AuthRequestV2NodeHandler::SMS_LIMIT_KEY, 'SMS codes', 'codes', LimitKind::PerPeriod));
        $limits->register(new LimitDefinition(AuthRequestV2NodeHandler::EMAIL_LIMIT_KEY, 'Email codes', 'codes', LimitKind::PerPeriod));
        $tenant = new TenantContext();
        $tenant->set(new RuntimeTenant(id: self::TENANT, schemaName: 'main'));

        $sent = &$this->sent;

        $handler = new AuthRequestV2NodeHandler(
            new OperandResolver(app(DataAccessorRegistryInterface::class), app(VariableResolverInterface::class), app(StateNamespacesInterface::class)),
            new OperatorComparator(),
            app(TemplateRenderer::class),
            new class () implements ContentTranslatorInterface {
                public function translate(string $key, string $language): string
                {
                    return $key;
                }

                public function resolveField(array|string $content, string $language): string
                {
                    return is_array($content) ? (string) ($content[$language] ?? reset($content)) : $content;
                }

                public function invalidate(string $tenantId, string $language): void
                {
                }

                public function invalidateAssistant(string $assistantId, string $language): void
                {
                }
            },
            new class ($sent) implements MessageSenderInterface {
                /** @param  list<array<string, mixed>>  $sent */
                public function __construct(private array &$sent)
                {
                }

                public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
                {
                    $this->sent[] = $payload;

                    return 'msg-' . count($this->sent);
                }
            },
            $this->store(),
            $registry,
            new PeriodQuota($limits, $meter ?? new UnlimitedUsageMeter(), $tenant, new LimitAnnouncer(new EventDispatcher(), $tenant)),
            $this->bus,
        );

        return $handler;
    }

    private function store(): AuthCodeStore
    {
        return new AuthCodeStore($this->cache, 'base64:dGVzdC1hcHAta2V5LWZvci1hdXRoLWNvZGVzLTEyMzQ=');
    }

    private function job(int $index = 0): SendOneTimeCodeJob
    {
        $jobs = $this->bus->dispatched(SendOneTimeCodeJob::class)->values();

        return $jobs[$index];
    }

    /**
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function apply(array $state, NodeExecutionResult $result): array
    {
        $this->results[] = $result;

        foreach ($result->stateChanges as $key => $value) {
            data_set($state, $key, $value);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function node(array $config): array
    {
        return ['id' => self::NODE, 'type' => 'auth_request', 'version' => 2, 'config' => $config];
    }

    private function context(?IncomingMessage $incoming = null, string $sessionId = self::SESSION): NodeExecutionContext
    {
        $written = &$this->written;

        return new NodeExecutionContext(
            tenantId: self::TENANT,
            contactId: 'contact-1',
            sessionId: $sessionId,
            nodeId: self::NODE,
            idempotencyKey: $sessionId . ':' . self::NODE,
            platform: 'telegram',
            resolvedLanguage: 'en',
            incoming: $incoming,
            contactWriter: new class ($written) implements ContactWriterInterface {
                /** @param  list<array{0: string, 1: mixed}>  $written */
                public function __construct(private array &$written)
                {
                }

                public function write(string $path, mixed $value): void
                {
                    $this->written[] = [$path, $value];
                }
            },
        );
    }

    private function text(string $text): IncomingMessage
    {
        return new IncomingMessage('u1', self::USER, self::USER, $text, IncomingMessageType::Text, 'telegram');
    }

    private function contactCard(string $phone, string $userId): IncomingMessage
    {
        return new IncomingMessage('u2', self::USER, self::USER, null, IncomingMessageType::Contact, 'telegram', [
            'contact' => ['phone_number' => $phone, 'user_id' => $userId],
        ]);
    }

    private function resendPress(): IncomingMessage
    {
        return new IncomingMessage('u3', self::USER, self::USER, CallbackDataCodec::encode(self::SESSION, AuthRequestV2NodeHandler::resendButtonId(self::NODE)), IncomingMessageType::CallbackQuery, 'telegram');
    }

    /**
     * @param  list<NodeConfigIssue>  $issues
     *
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (NodeConfigIssue $issue): string => $issue->code, $issues);
    }
}
