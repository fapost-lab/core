<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Expression\Engines\TemplateEngine;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Validation\ContactContextChecker;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Flow\Contracts\ExpressionEngineInterface;
use Fapost\Foundation\Flow\DTO\ExpressionContext;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The expression engine is the author's choice per flow (ADR expression-engine-chosen-per-flow): the draft holds it,
 * publishing snapshots it onto the definition and checks the flow with it, a republish by a template keeps the host's
 * snapshot, an engine the registry does not know is refused, and a template is placed only in a flow of its engine.
 */
final class FlowExpressionEngineTest extends FeatureTestCase
{
    private const array SCHEDULE = ['type' => 'schedule', 'is_active' => true, 'priority' => 100, 'config' => ['cron' => '0 9 * * *', 'timezone' => 'UTC']];

    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));
        // The registry is frozen only outside testing.
        $this->app->make(ExpressionEngineRegistry::class)->register(new AngleTestEngine());

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_a_publish_snapshots_the_engine_the_draft_chose(): void
    {
        $default = $this->draft([$this->endNode()]);
        $chosen  = $this->draft([$this->endNode()], engine: AngleTestEngine::ID);

        $this->assertSame(TemplateEngine::ID, $default->fresh()->expression_engine, 'a new draft gets the template engine');
        $this->assertSame(TemplateEngine::ID, $this->publish($default->flow_id)->expression_engine);
        $this->assertSame(AngleTestEngine::ID, $this->publish($chosen->flow_id)->expression_engine);
    }

    public function test_a_flow_whose_engine_is_not_installed_is_refused_and_nothing_is_written(): void
    {
        $draft = $this->draft([$this->endNode()], engine: 'gone_engine');

        $errors = $this->refusal($draft->flow_id);

        $this->assertContains('expression_engine_unknown', array_column($errors, 'code'));
        $this->assertSame('expression_engine', $errors[array_search('expression_engine_unknown', array_column($errors, 'code'), true)]['path']);
        $this->assertFalse(FlowDefinition::query()->where('flow_id', $draft->flow_id)->exists());
    }

    public function test_a_template_is_placed_only_in_a_flow_of_its_engine(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->host($template->flow_id, engine: AngleTestEngine::ID);

        $errors = $this->refusal($host->flow_id);

        $this->assertSame(['template_engine_mismatch'], array_column($errors, 'code'));
        $this->assertSame('nodes.ref', $errors[0]['path']);
        $this->assertFalse(FlowDefinition::query()->where('flow_id', $host->flow_id)->exists());
    }

    public function test_a_template_republish_keeps_the_hosts_published_engine_not_its_drafts_new_choice(): void
    {
        $template = $this->publishedTemplate();
        $host     = $this->host($template->flow_id);
        $this->assertSame(TemplateEngine::ID, $this->publish($host->flow_id)->expression_engine);

        // An unpublished change of the host's choice: a republish must not take it.
        $host->update(['expression_engine' => AngleTestEngine::ID]);
        $this->publish($template->flow_id);

        $republished = FlowDefinition::query()->where('flow_id', $host->flow_id)->active()->sole();
        $this->assertSame(2, $republished->version);
        $this->assertSame(TemplateEngine::ID, $republished->expression_engine);
    }

    public function test_the_contact_check_reads_references_with_the_flows_engine(): void
    {
        $nodes = static fn (string $value): array => ['set' => ['id' => 'set', 'type' => 'assign', 'version' => 1, 'config' => [
            'operations' => [['variable' => ['name' => 'greeting', 'storage' => 'session'], 'value' => $value]],
        ]]];

        $this->assertSame([], $this->contactErrors($nodes('Hi <<contact.name>>'), TemplateEngine::ID), 'not a template reference');
        $this->assertSame([], $this->contactErrors($nodes('Hi {{contact.name}}'), AngleTestEngine::ID), 'not a reference of this engine');

        $errors = $this->contactErrors($nodes('Hi <<contact.name>>'), AngleTestEngine::ID);
        $this->assertCount(1, $errors);
        $this->assertSame(ContactContextChecker::CODE, $errors[0]->code);
        $this->assertStringContainsString("it reads 'contact.name'", $errors[0]->message);

        $this->assertCount(1, $this->contactErrors($nodes('Hi {{contact.name}}'), null), 'no engine given means template');
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     *
     * @return list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>
     */
    private function contactErrors(array $nodes, ?string $engineId): array
    {
        $result = $this->app->make(ValidateFlowService::class)->execute(nodes: $nodes, triggers: [self::SCHEDULE], engineId: $engineId);

        return array_values(array_filter($result->errors, static fn ($error): bool => ContactContextChecker::CODE === $error->code));
    }

    private function publishedTemplate(): FlowDraft
    {
        $template = FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [
                ['id' => 't-in', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => [['variable' => ['name' => 'inside', 'storage' => 'session'], 'value' => 'yes']]]],
                ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done', 'label' => 'Done']],
            ],
            'edges' => [['id' => 'te1', 'from' => 't-in', 'to' => 't-done', 'handle' => 'default']],
        ]);

        $this->assertSame(TemplateEngine::ID, $this->publish($template->flow_id)->expression_engine);

        return $template;
    }

    private function host(string $templateFlowId, string $engine = TemplateEngine::ID): FlowDraft
    {
        return $this->draft(
            [
                ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => ['template_flow_id' => $templateFlowId, 'outputs' => [['name' => 'done', 'label' => 'Done']]]],
                $this->endNode(),
            ],
            [['id' => 'he1', 'from' => 'ref', 'to' => 'end', 'handle' => 'done']],
            $engine,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function draft(array $nodes, array $edges = [], ?string $engine = null): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes,
            'edges'        => $edges,
            ...(null === $engine ? [] : ['expression_engine' => $engine]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function endNode(): array
    {
        return ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']];
    }

    private function publish(string $flowId): FlowDefinition
    {
        try {
            return $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            $this->fail('Publish refused: ' . json_encode(array_map(static fn ($error): string => "{$error->code}: {$error->message}", $exception->errors)));
        }
    }

    /**
     * @return list<array{code: string, path: string}>
     */
    private function refusal(string $flowId): array
    {
        try {
            $this->app->make(PublishFlowService::class)->execute($flowId);
        } catch (FlowValidationException $exception) {
            return array_map(static fn ($error): array => ['code' => $error->code, 'path' => $error->path], $exception->errors);
        }

        $this->fail("Publishing {$flowId} should be refused.");
    }
}

/**
 * A second engine with a syntax of its own, `<<path>>`, so a test can tell which engine's references were read.
 */
final class AngleTestEngine implements ExpressionEngineInterface
{
    public const string ID = 'angle_test';

    public function id(): string
    {
        return self::ID;
    }

    public function version(): int
    {
        return 1;
    }

    public function evaluate(string $source, ExpressionContext $context): mixed
    {
        return $source;
    }

    public function validate(string $source): void
    {
    }

    public function extractReferences(string $source): array
    {
        preg_match_all('/<<\s*([\w.]+)\s*>>/', $source, $matches);

        return array_values($matches[1]);
    }
}
