<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The `notify` contacts mode is retired: a publish that adds such a node or changes one is refused
 * (`notify_contacts_retired`), an unchanged one publishes with a warning, and a template republishing a host that still
 * has one is not stopped by it.
 */
final class NotifyContactsPublishTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_a_new_contacts_node_is_refused_and_a_staff_node_is_not(): void
    {
        $this->assertSame(['notify_contacts_retired@nodes.notify.config.mode'], $this->refusal($this->draft($this->notify())));

        $staff = $this->draft($this->notify(['mode' => 'staff', 'target' => 'assistant', 'message' => 'Help']));

        $this->assertSame(1, $this->publish($staff)->version);
    }

    public function test_an_unchanged_contacts_node_publishes_with_a_warning_and_a_changed_one_is_refused(): void
    {
        $draft = $this->publishedBeforeTheRetirement($this->notify(['tags' => ['vip', 'lead'], 'message' => ['ru' => 'Привет', 'en' => 'Hi']]));

        $warnings = $this->app->make(ValidateFlowService::class)->execute(nodes: $draft->nodes, edges: $draft->edges)->warnings;
        $this->assertContains('notify_contacts_deprecated', array_map(static fn ($warning): string => $warning->code, $warnings));

        // The same node, its tags and languages in another order, is unchanged.
        $draft->forceFill(['nodes' => $this->notify(['tags' => ['lead', 'vip'], 'message' => ['en' => 'Hi', 'ru' => 'Привет']])])->save();
        $this->assertSame(2, $this->publish($draft)->version);

        foreach ([['message' => ['en' => 'Hi again', 'ru' => 'Привет']], ['tags' => ['vip']], ['contact_target' => 'all'], ['assistant_id' => (string) Str::uuid()]] as $change) {
            $draft->forceFill(['nodes' => $this->notify(['tags' => ['vip', 'lead'], 'message' => ['en' => 'Hi', 'ru' => 'Привет'], ...$change])])->save();

            $this->assertSame(['notify_contacts_retired@nodes.notify.config.mode'], $this->refusal($draft), json_encode($change, JSON_THROW_ON_ERROR));
        }

        // Replacing it needs nothing.
        $draft->forceFill(['nodes' => $this->notify(['mode' => 'staff', 'target' => 'assistant', 'message' => 'Help'])])->save();
        $this->assertSame(3, $this->publish($draft)->version);
    }

    public function test_a_template_cannot_bring_a_contacts_node(): void
    {
        $template = $this->template(['mode' => 'contacts', 'assistant_id' => (string) $this->assistant->getKey(), 'contact_target' => 'all', 'message' => 'Hi']);

        $this->assertSame(['notify_contacts_retired@nodes.t-notify.config.mode'], $this->refusal($template));
    }

    public function test_a_template_republishes_a_host_that_still_has_an_unchanged_contacts_node(): void
    {
        $template = $this->template(['mode' => 'staff', 'target' => 'assistant', 'message' => 'Hi']);
        $this->publish($template);

        $nodes = [
            ...$this->notify(['mode' => 'staff', 'target' => 'assistant', 'message' => 'Hi']),
            ['id' => 'ref', 'type' => 'template', 'version' => 1, 'config' => ['template_flow_id' => $template->flow_id, 'outputs' => [['name' => 'done', 'label' => 'Done']]]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
        $host = $this->draft($nodes, [
            ['id' => 'e1', 'from' => 'notify', 'to' => 'ref', 'handle' => 'default'],
            ['id' => 'e2', 'from' => 'ref', 'to' => 'end', 'handle' => 'done'],
        ]);
        $this->publish($host);

        // The host's notify node was in contacts mode before the retirement, in its draft and its published version.
        $legacy = $this->notify()[0];
        $active = FlowDefinition::query()->where('flow_id', $host->flow_id)->active()->sole();
        $active->forceFill(['nodes' => array_map(static fn (array $node): array => 'notify' === $node['id'] ? $legacy : $node, $active->nodes)])->save();

        // An unpublished edit of it in the host's draft would be refused on the host's own publish, not on the template's.
        $host->forceFill(['nodes' => [$this->notify(['message' => 'Hi, edited'])[0], ...array_slice($nodes, 1)]])->save();
        $this->assertSame(['notify_contacts_retired@nodes.notify.config.mode'], $this->refusal($host));

        $template->forceFill(['nodes' => [[...$template->nodes[0], 'config' => ['mode' => 'staff', 'target' => 'assistant', 'message' => 'Hi there']], $template->nodes[1]]])->save();
        $this->publish($template);

        $republished = FlowDefinition::query()->where('flow_id', $host->flow_id)->active()->sole();
        $this->assertSame(2, $republished->version);
        // Compared as a map: jsonb does not keep the order of the keys.
        $this->assertEquals($legacy['config'], collect($republished->nodes)->firstWhere('id', 'notify')['config'], 'the host keeps its published node');
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<array<string, mixed>>
     */
    private function notify(array $config = []): array
    {
        return [['id' => 'notify', 'type' => 'notify', 'version' => 1, 'config' => [
            'mode'           => 'contacts',
            'assistant_id'   => (string) $this->assistant->getKey(),
            'contact_target' => 'tag',
            'tags'           => ['vip'],
            'message'        => 'Hi',
            ...$config,
        ]]];
    }

    /**
     * A draft whose notify node was published in contacts mode before the mode was retired.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function publishedBeforeTheRetirement(array $nodes): FlowDraft
    {
        $draft = $this->draft($nodes);

        FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => $draft->flow_id,
            'version'   => 1,
            'name'      => (string) $draft->name,
            'nodes'     => $nodes,
            'edges'     => [],
            'is_active' => true,
        ]);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $notifyConfig
     */
    private function template(array $notifyConfig): FlowDraft
    {
        return FlowDraft::factory()->template()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [
                ['id' => 't-notify', 'type' => 'notify', 'version' => 1, 'config' => $notifyConfig],
                ['id' => 't-done', 'type' => 'template_output', 'version' => 1, 'config' => ['name' => 'done', 'label' => 'Done']],
            ],
            'edges' => [['id' => 'te1', 'from' => 't-notify', 'to' => 't-done', 'handle' => 'default']],
        ]);
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

    private function publish(FlowDraft $draft): FlowDefinition
    {
        return $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
    }

    /**
     * @return list<string> the refusal's errors as `code@path`
     */
    private function refusal(FlowDraft $draft): array
    {
        try {
            $this->publish($draft);
        } catch (FlowValidationException $exception) {
            foreach ($exception->errors as $error) {
                $this->assertNotSame('builder.validation.notify_contacts_retired', $error->message, 'the refusal is translated');
            }

            return array_map(static fn ($error): string => "{$error->code}@{$error->path}", $exception->errors);
        }

        $this->fail('The publish should have been refused.');
    }
}
