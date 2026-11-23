<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Validation\ContactContextChecker;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Contracts\DeclaresContextRequirementInterface;
use Fapost\Foundation\Flow\Enums\NodeContextRequirement;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * A flow whose trigger gives no contact (schedule, webhook, API) cannot be validated or published while a node in it
 * needs a contact: a contact node type, an undeclared (Solution) node, a `contact.*` or `module.*` read, or a contact
 * variable. The error names the node. A message or event trigger, or no trigger at all, keeps every node allowed.
 */
final class ContactContextValidationTest extends FeatureTestCase
{
    private const array SCHEDULE = ['type' => 'schedule', 'is_active' => true, 'priority' => 100, 'config' => ['cron' => '0 9 * * *', 'timezone' => 'UTC']];

    private const array MESSAGE = ['type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => ['keywords' => ['hi']]];

    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(ContactFreeTestHandler::class);
        $registry->register(UndeclaredTestHandler::class);

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function nodesThatNeedAContact(): iterable
    {
        yield 'a contact node type' => [
            ['type' => 'send_message', 'config' => ['content_type' => 'text', 'text' => ['en' => 'Hi']]],
            "the node type 'send_message' reaches the contact of the run",
        ];
        yield 'a node that declares nothing' => [
            ['type' => 'undeclared_test', 'config' => []],
            "the node type 'undeclared_test' reaches the contact of the run",
        ];
        yield 'a contact placeholder' => [
            ['type' => 'assign', 'config' => ['operations' => [['variable' => ['name' => 'greeting', 'storage' => 'session'], 'value' => 'Hi {{ contact.name }}']]]],
            "it reads 'contact.name'",
        ];
        yield 'a module placeholder' => [
            ['type' => 'assign', 'config' => ['operations' => [['variable' => ['name' => 'dept', 'storage' => 'session'], 'value' => '{{module.hr.department}}']]]],
            "it reads 'module.hr.department'",
        ];
        yield 'a legacy contact path' => [
            ['type' => 'branch', 'config' => ['rules' => [['left' => 'contact.age', 'operator' => 'gt', 'right' => 18]]]],
            "it reads 'contact.age'",
        ];
        yield 'a source operand on the contact' => [
            ['type' => 'branch', 'config' => ['rules' => [['left' => ['ref' => 'source', 'source' => 'contact', 'field' => 'age']]]]],
            "it reads 'contact'",
        ];
        yield 'a contact variable written by assign' => [
            ['type' => 'assign', 'config' => ['operations' => [['variable' => ['name' => 'tier', 'storage' => 'contact'], 'value' => 'gold']]]],
            "it uses the contact variable 'tier'",
        ];
        yield 'a call node that saves its response to a contact variable' => [
            ['type' => 'call', 'config' => ['transport' => 'http', 'target' => 'POST https://example.com/hook', 'save_to_variable' => ['name' => 'reply', 'storage' => 'contact']]],
            "it uses the contact variable 'reply'",
        ];
        yield 'a call node whose target reads the contact' => [
            ['type' => 'call', 'config' => ['transport' => 'http', 'target' => 'POST https://example.com/hook/{{contact.id}}']],
            "it reads 'contact.id'",
        ];
        yield 'a bare contact placeholder' => [
            ['type' => 'assign', 'config' => ['operations' => [['variable' => ['name' => 'who', 'storage' => 'session'], 'value' => '{{contact}}']]]],
            "it reads 'contact'",
        ];
        yield 'a source operand on a module' => [
            ['type' => 'branch', 'config' => ['rules' => [['left' => ['ref' => 'source', 'source' => 'module', 'field' => 'hr.department']]]]],
            "it reads 'module'",
        ];
        yield 'a legacy assign to the contact' => [
            ['type' => 'assign', 'config' => ['target' => 'contact', 'key' => 'tier', 'value' => 'gold']],
            'it writes to the contact',
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    #[DataProvider('nodesThatNeedAContact')]
    public function test_a_node_that_needs_a_contact_is_refused_under_a_schedule_trigger(array $node, string $reason): void
    {
        $errors = $this->contactErrors(['n1' => ['id' => 'n1', 'version' => 1, ...$node]], [self::SCHEDULE]);

        $this->assertCount(1, $errors);
        $this->assertSame('nodes.n1', $errors[0]->path);
        $this->assertStringContainsString("Node 'n1' ({$node['type']}) needs a contact: {$reason}.", $errors[0]->message);
        $this->assertStringContainsString('schedule trigger', $errors[0]->message);
    }

    public function test_nodes_that_need_no_contact_pass_under_a_schedule_trigger(): void
    {
        $errors = $this->contactErrors([
            'branch' => ['id' => 'branch', 'type' => 'branch', 'version' => 1, 'config' => [
                'rules' => [['left' => 'flow.order_id', 'operator' => 'eq', 'right' => '{{flow.expected}}']],
                'note'  => 'contact.us is plain text here',
            ]],
            'assign' => ['id' => 'assign', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => [['variable' => ['name' => 'n', 'storage' => 'session'], 'value' => 1]]]],
            'delay'  => ['id' => 'delay', 'type' => 'delay', 'version' => 1, 'config' => ['seconds' => 60]],
            'free'   => ['id' => 'free', 'type' => 'contact_free_test', 'version' => 1, 'config' => []],
            'end'    => ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [self::SCHEDULE]);

        $this->assertSame([], $errors);
    }

    public function test_a_scheduled_flow_that_calls_an_endpoint_is_published(): void
    {
        $nodes = [
            ['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'http', 'target' => 'POST https://example.com/hook/{{flow.order_id}}']],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [
            ['id' => 'e1', 'from' => 'call', 'to' => 'end', 'handle' => 'success'],
            ['id' => 'e2', 'from' => 'call', 'to' => 'end', 'handle' => 'error'],
        ];

        $scheduled = $this->draft($nodes, $edges);
        $this->storeTrigger($scheduled, self::SCHEDULE);

        $definition = $this->app->make(PublishFlowService::class)->execute($scheduled->flow_id);

        $this->assertSame(1, $definition->version);
    }

    public function test_a_message_trigger_an_empty_list_and_no_trigger_allow_contact_nodes(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'send_message', 'version' => 1, 'config' => ['content_type' => 'text', 'text' => ['en' => 'Hi']]]];

        $this->assertSame([], $this->contactErrors($nodes, [self::MESSAGE]));
        $this->assertSame([], $this->contactErrors($nodes, []));
        $this->assertSame([], $this->contactErrors($nodes, null));
    }

    public function test_webhook_and_api_triggers_give_no_contact_and_an_event_trigger_does(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]];

        $this->assertCount(1, $this->contactErrors($nodes, [['type' => 'webhook']]));
        $this->assertCount(1, $this->contactErrors($nodes, [['type' => 'api']]));
        $this->assertSame([], $this->contactErrors($nodes, [['type' => 'event']]));
    }

    public function test_an_event_trigger_on_a_core_event_gives_no_contact_and_one_on_a_flow_event_does(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]];

        $errors = $this->contactErrors($nodes, [['type' => 'event', 'config' => ['event_name' => 'core.contact.tag_added']]]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("The flow's event trigger starts it without one.", $errors[0]->message);

        $this->assertSame([], $this->contactErrors($nodes, [['type' => 'event', 'config' => ['event_name' => 'orders.paid']]]));
    }

    public function test_without_a_list_a_live_core_event_trigger_makes_the_flow_contactless(): void
    {
        $draft = $this->draft([['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]]);
        $nodes = ['ask' => $draft->nodes[0]];

        $this->storeTrigger($draft, ['type' => 'event', 'config' => ['event_name' => 'orders.paid']]);
        $this->assertSame([], $this->contactErrors($nodes, null, $draft->flow_id), "a flow's event gives a contact");

        $this->storeTrigger($draft, ['type' => 'event', 'config' => ['event_name' => 'core.broadcast.completed']]);
        $this->assertCount(1, $this->contactErrors($nodes, null, $draft->flow_id), 'a Core event gives none');
    }

    public function test_without_a_trigger_list_the_live_triggers_of_the_flow_decide(): void
    {
        $draft = $this->draft([['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]]);
        $nodes = ['ask' => $draft->nodes[0]];

        $this->assertSame([], $this->contactErrors($nodes, null, $draft->flow_id), 'no stored trigger: a contact');

        $this->storeTrigger($draft, ['type' => 'schedule', 'is_active' => false, 'config' => self::SCHEDULE['config']]);

        $this->assertCount(1, $this->contactErrors($nodes, null, $draft->flow_id), 'an inactive schedule trigger counts');
        $this->assertSame([], $this->contactErrors($nodes, [self::MESSAGE], $draft->flow_id), 'the given list wins');
    }

    public function test_a_message_and_a_schedule_trigger_together_refuse_a_contact_node_and_name_the_schedule(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'send_message', 'version' => 1, 'config' => ['content_type' => 'text', 'text' => ['en' => 'Hi']]]];

        $errors = $this->contactErrors($nodes, [self::MESSAGE, self::SCHEDULE]);

        $this->assertCount(1, $errors);
        $this->assertSame('nodes.ask', $errors[0]->path);
        $this->assertStringContainsString("The flow's schedule trigger starts it without one.", $errors[0]->message);
        $this->assertStringNotContainsString('message', mb_substr($errors[0]->message, (int) mb_strpos($errors[0]->message, "The flow's")));
    }

    public function test_an_inactive_contactless_trigger_in_the_list_still_counts(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]];

        $this->assertCount(1, $this->contactErrors($nodes, [self::MESSAGE, [...self::SCHEDULE, 'is_active' => false]]));
    }

    public function test_every_contactless_type_is_named_once(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]];

        $errors = $this->contactErrors($nodes, [self::SCHEDULE, ['type' => 'webhook'], self::SCHEDULE]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("The flow's schedule, webhook triggers start it without one.", $errors[0]->message);
    }

    public function test_without_a_list_the_live_message_and_schedule_rows_together_refuse_a_contact_node(): void
    {
        $draft = $this->draft([['id' => 'ask', 'type' => 'undeclared_test', 'version' => 1, 'config' => []]]);
        $nodes = ['ask' => $draft->nodes[0]];

        $this->storeTrigger($draft, self::MESSAGE);
        $this->assertSame([], $this->contactErrors($nodes, null, $draft->flow_id), 'a message row alone gives a contact');

        $this->storeTrigger($draft, self::SCHEDULE);
        $errors = $this->contactErrors($nodes, null, $draft->flow_id);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('schedule trigger starts', $errors[0]->message);
    }

    public function test_publish_refuses_a_contact_node_in_a_flow_with_a_schedule_trigger_and_allows_it_with_a_message_trigger(): void
    {
        $nodes = [
            ['id' => 'work', 'type' => 'undeclared_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [['id' => 'e1', 'from' => 'work', 'to' => 'end', 'handle' => 'default']];

        $scheduled = $this->draft($nodes, $edges);
        $this->storeTrigger($scheduled, self::SCHEDULE);

        try {
            $this->app->make(PublishFlowService::class)->execute($scheduled->flow_id);
            $this->fail('The publish should be refused.');
        } catch (FlowValidationException $exception) {
            $refusal = array_values(array_filter($exception->errors, static fn ($error): bool => ContactContextChecker::CODE === $error->code));

            $this->assertCount(1, $refusal);
            $this->assertSame('nodes.work', $refusal[0]->path);
        }

        $conversational = $this->draft($nodes, $edges);
        $this->storeTrigger($conversational, self::MESSAGE);

        $definition = $this->app->make(PublishFlowService::class)->execute($conversational->flow_id);

        $this->assertSame(1, $definition->version);
    }

    public function test_a_scheduled_flow_that_messages_an_addressee_is_published(): void
    {
        $nodes = [
            ['id' => 'tell', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type' => 'text',
                'text'         => ['en' => 'Your order {{flow.order_id}} has shipped'],
                'addressee'    => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [['id' => 'e1', 'from' => 'tell', 'to' => 'end', 'handle' => 'default']];

        $scheduled = $this->draft($nodes, $edges);
        $this->storeTrigger($scheduled, self::SCHEDULE);

        $definition = $this->app->make(PublishFlowService::class)->execute($scheduled->flow_id);

        $this->assertSame(1, $definition->version);
    }

    public function test_an_addressee_does_not_excuse_a_contact_read_or_an_empty_expression(): void
    {
        $node = static fn (array $config): array => ['n1' => ['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => [
            'content_type' => 'text',
            ...$config,
        ]]];

        $read = $this->contactErrors($node([
            'text'      => ['en' => 'Hi {{contact.name}}'],
            'addressee' => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
        ]), [self::SCHEDULE]);
        $this->assertCount(1, $read);
        $this->assertStringContainsString("it reads 'contact.name'", $read[0]->message);

        $empty = $this->contactErrors($node([
            'text'      => ['en' => 'Hi'],
            'addressee' => ['kind' => 'contact', 'contact' => ''],
        ]), [self::SCHEDULE]);
        $this->assertCount(1, $empty);
        $this->assertStringContainsString("the node type 'send_message' reaches the contact of the run", $empty[0]->message);
    }

    public function test_a_scheduled_flow_that_starts_a_flow_for_a_contact_is_published_and_a_contact_read_is_not(): void
    {
        $target = $this->draft([['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]]);
        $node   = static fn (string $contact, string $flowId): array => [
            ['id' => 'start', 'type' => 'start_flow', 'version' => 1, 'config' => [
                'flow_id'   => $flowId,
                'addressee' => ['kind' => 'contact', 'contact' => $contact],
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $edges = [['id' => 'e1', 'from' => 'start', 'to' => 'end', 'handle' => 'started']];

        $scheduled = $this->draft($node('{{flow.customer_id}}', $target->flow_id), $edges);
        $this->storeTrigger($scheduled, self::SCHEDULE);

        $this->assertSame(1, $this->app->make(PublishFlowService::class)->execute($scheduled->flow_id)->version);

        $read = $this->contactErrors(['start' => $node('{{contact.id}}', $target->flow_id)[0]], [self::SCHEDULE]);
        $this->assertCount(1, $read);
        $this->assertStringContainsString("it reads 'contact.id'", $read[0]->message);
    }

    public function test_a_start_flow_target_must_be_a_flow_of_the_same_assistant(): void
    {
        $other   = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $foreign = FlowDraft::factory()->create(['tenant_id' => $this->tenantId, 'assistant_id' => $other->getKey()]);
        $caller  = $this->draft([]);

        $codes = function (string $target) use ($caller): array {
            $result = $this->app->make(ValidateFlowService::class)->execute(
                nodes: ['start' => ['id' => 'start', 'type' => 'start_flow', 'version' => 1, 'config' => [
                    'flow_id'   => $target,
                    'addressee' => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
                ]]],
                triggers: [self::MESSAGE],
                flowId: $caller->flow_id,
            );

            return array_map(static fn ($error): string => "{$error->code}@{$error->path}", $result->errors);
        };

        $this->assertContains('start_flow_target_unknown@nodes.start.config.flow_id', $codes($foreign->flow_id));
        $this->assertContains('start_flow_target_unknown@nodes.start.config.flow_id', $codes((string) Str::uuid()));
        $this->assertContains('start_flow_template_in_flow_id@nodes.start.config.flow_id', $codes('{{flow.target}}'));
        $this->assertNotContains('start_flow_target_unknown@nodes.start.config.flow_id', $codes($this->draft([])->flow_id));
    }

    public function test_a_message_to_an_addressee_cannot_wait_for_an_answer_in_any_flow(): void
    {
        $nodes = ['ask' => ['id' => 'ask', 'type' => 'send_message', 'version' => 1, 'config' => [
            'content_type'  => 'text_with_keyboard',
            'keyboard_mode' => 'inline',
            'text'          => ['en' => 'Approve?'],
            'buttons'       => [['id' => (string) Str::uuid(), 'label' => ['en' => 'Yes'], 'value' => 'yes']],
            'addressee'     => ['kind' => 'contact', 'contact' => '{{flow.manager_id}}'],
        ]]];

        foreach ([self::SCHEDULE, self::MESSAGE] as $trigger) {
            $result = $this->app->make(ValidateFlowService::class)->execute(nodes: $nodes, triggers: [$trigger]);
            $codes  = array_map(static fn ($error): string => "{$error->code}@{$error->path}", $result->errors);

            $this->assertContains('addressee_cannot_wait@nodes.ask.config.content_type', $codes);
        }
    }

    public function test_a_template_publish_that_puts_a_contact_node_into_a_scheduled_host_is_refused(): void
    {
        $template = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [
                ['id' => 't-in', 'type' => 'contact_free_test', 'version' => 1, 'config' => []],
                ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done', 'label' => 'Done']],
            ],
            'edges' => [['id' => 'te1', 'from' => 't-in', 'to' => 't-done', 'handle' => 'default']],
        ]);
        $this->app->make(PublishFlowService::class)->execute($template->flow_id);

        $host = $this->draft(
            [
                ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => ['template_flow_id' => $template->flow_id, 'outputs' => [['name' => 'done', 'label' => 'Done']]]],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            [['id' => 'he1', 'from' => 'ref', 'to' => 'end', 'handle' => 'done']],
        );
        $this->storeTrigger($host, self::SCHEDULE);
        $this->app->make(PublishFlowService::class)->execute($host->flow_id);

        $template->refresh();
        $template->update(['nodes' => [
            ['id' => 't-in', 'type' => 'undeclared_test', 'version' => 1, 'config' => []],
            $template->nodes[1],
        ]]);

        try {
            $this->app->make(PublishFlowService::class)->execute($template->flow_id);
            $this->fail('The template publish should be refused: its scheduled host would hold a contact node.');
        } catch (FlowValidationException $exception) {
            $this->assertSame(['template_breaks_flow'], array_values(array_unique(array_map(static fn ($error): string => $error->code, $exception->errors))));
            $this->assertStringContainsString('needs a contact', $exception->errors[0]->message);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>|null      $triggers
     *
     * @return list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>
     */
    private function contactErrors(array $nodes, ?array $triggers, ?string $flowId = null): array
    {
        $result = $this->app->make(ValidateFlowService::class)->execute(nodes: $nodes, triggers: $triggers, flowId: $flowId);

        return array_values(array_filter($result->errors, static fn ($error): bool => ContactContextChecker::CODE === $error->code));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function draft(array $nodes, array $edges = []): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes,
            'edges'        => $edges,
        ]);
    }

    /**
     * @param  array<string, mixed>  $trigger
     */
    private function storeTrigger(FlowDraft $draft, array $trigger): void
    {
        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => $trigger['type'],
            'is_active'    => $trigger['is_active'] ?? true,
            'priority'     => $trigger['priority'] ?? 100,
            'config'       => $trigger['config'],
        ]);
    }
}

/**
 * A node that declares it needs no contact.
 */
final class ContactFreeTestHandler implements NodeHandlerInterface, DeclaresContextRequirementInterface
{
    public function type(): string
    {
        return 'contact_free_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Contact-free test';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function contextRequirement(): NodeContextRequirement
    {
        return NodeContextRequirement::None;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

/**
 * A node that declares nothing, like every Solution node today: it counts as needing a contact.
 */
final class UndeclaredTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'undeclared_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Undeclared test';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}
