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
    public function test_it_prefers_exact_match_over_higher_priority_contains_match(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-contains',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['vacation'],
                'phrases'  => [],
            ],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-exact',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 200,
            'config'       => [
                'keywords' => ['vacation leave'],
                'phrases'  => [],
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => 'vacation leave'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-exact', $trigger->flowId);
    }

    public function test_it_matches_normalized_text_before_contains_fallback(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-normalized',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['otp'],
                'phrases'  => [],
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => ' OTP!!! '],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-normalized', $trigger->flowId);
    }

    public function test_it_uses_contains_fallback_after_exact_lookup(): void
    {
        $this->createAssistant('assistant-1');

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-contains',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['vacation'],
                'phrases'  => [],
            ],
        ]);

        $resolver = $this->app->make(MessageTriggerResolver::class);

        $trigger = $resolver->resolve(
            new TriggerContext(
                type: FlowTriggerType::Message->value,
                tenantId: 'tenant-1',
                assistantId: 'assistant-1',
                payload: ['text' => 'I want vacation tomorrow'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame('flow-contains', $trigger->flowId);
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
                'phrases'  => [],
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
                'phrases'  => [],
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
                'phrases'  => [],
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

    public function test_it_rejects_empty_message_trigger_config(): void
    {
        $this->createAssistant('assistant-1');

        $this->expectException(InvalidArgumentException::class);

        FlowTrigger::query()->create([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'flow_id'      => 'flow-invalid-message',
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => [],
                'phrases'  => [],
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
