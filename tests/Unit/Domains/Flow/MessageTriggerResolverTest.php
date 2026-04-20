<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\Resolvers\MessageTriggerResolver;
use FAPost\Foundation\Flow\DTO\TriggerContext;
use InvalidArgumentException;
use Tests\Feature\FeatureTestCase;

final class MessageTriggerResolverTest extends FeatureTestCase
{
    public function test_it_resolves_first_match_by_priority(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-contains',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 200,
            'config'       => [
                'keywords' => ['help'],
                'match'    => 'contains',
            ],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-exact',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['help'],
                'match'    => 'exact',
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => 'help'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-exact', $trigger->flowId);
    }

    public function test_it_supports_regex_match_mode(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-regex',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/^\d{4}$/'],
                'match'    => 'regex',
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => '1234'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-regex', $trigger->flowId);
    }

    public function test_it_does_not_mutate_regex_case_sensitivity(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-case-sensitive-regex',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/[A-Z]{4}/'],
                'match'    => 'regex',
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => 'abcd'],
            )
        );

        $this->assertNull($trigger);
    }

    public function test_it_uses_global_trigger_when_assistant_specific_does_not_match(): void
    {
        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => null,
            'flow_id'      => 'flow-global',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/start'],
                'match'    => 'exact',
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => '/start'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-global', $trigger->flowId);
    }

    public function test_it_prefers_assistant_specific_trigger_over_global_with_same_priority(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => null,
            'flow_id'      => 'flow-global',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/start'],
                'match'    => 'exact',
            ],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-assistant',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/start'],
                'match'    => 'exact',
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => '/start'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-assistant', $trigger->flowId);
    }

    public function test_it_rejects_invalid_regex_in_message_trigger_config(): void
    {
        $this->createAssistant('assistant-1');

        $this->expectException(InvalidArgumentException::class);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-invalid-regex',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/[a-z+/'],
                'match'    => 'regex',
            ],
        ]);
    }

    private function createAssistant(string $assistantId): void
    {
        $assistant = new Assistant();
        $assistant->forceFill([
            'id'        => $assistantId,
            'tenant_id' => 'tenant-1',
            'name'      => 'Test assistant',
            'is_active' => true,
            'settings'  => [],
        ]);
        $assistant->save();
    }
}
