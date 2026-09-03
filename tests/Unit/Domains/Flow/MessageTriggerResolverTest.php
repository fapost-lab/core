<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\Resolvers\MessageTriggerResolver;
use Fapost\Foundation\Flow\DTO\TriggerContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\FeatureTestCase;

final class MessageTriggerResolverTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string ASSISTANT_ID = '00000000-0000-0000-0000-000000000002';

    public function test_it_prefers_exact_match_over_higher_priority_contains_match(): void
    {
        $this->createAssistant(self::ASSISTANT_ID);

        $flowContainsId = (string) Str::uuid();
        $flowExactId    = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => self::ASSISTANT_ID,
            'flow_id'      => $flowContainsId,
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['vacation'],
                'phrases'  => [],
            ],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => self::ASSISTANT_ID,
            'flow_id'      => $flowExactId,
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
                tenantId: self::TENANT_ID,
                assistantId: self::ASSISTANT_ID,
                payload: ['text' => 'vacation leave'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame($flowExactId, $trigger->flowId);
    }

    public function test_it_matches_normalized_text_before_contains_fallback(): void
    {
        $this->createAssistant(self::ASSISTANT_ID);

        $flowNormalizedId = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => self::ASSISTANT_ID,
            'flow_id'      => $flowNormalizedId,
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
                tenantId: self::TENANT_ID,
                assistantId: self::ASSISTANT_ID,
                payload: ['text' => ' OTP!!! '],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame($flowNormalizedId, $trigger->flowId);
    }

    public function test_it_uses_contains_fallback_after_exact_lookup(): void
    {
        $this->createAssistant(self::ASSISTANT_ID);

        $flowContainsId = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => self::ASSISTANT_ID,
            'flow_id'      => $flowContainsId,
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
                tenantId: self::TENANT_ID,
                assistantId: self::ASSISTANT_ID,
                payload: ['text' => 'I want vacation tomorrow'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame($flowContainsId, $trigger->flowId);
    }

    public function test_it_uses_global_trigger_when_assistant_specific_does_not_match(): void
    {
        $flowGlobalId = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => null,
            'flow_id'      => $flowGlobalId,
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
                tenantId: self::TENANT_ID,
                assistantId: self::ASSISTANT_ID,
                payload: ['text' => '/start'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame($flowGlobalId, $trigger->flowId);
    }

    public function test_it_prefers_assistant_specific_trigger_over_global_with_same_priority(): void
    {
        $this->createAssistant(self::ASSISTANT_ID);

        $flowGlobalId    = (string) Str::uuid();
        $flowAssistantId = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => null,
            'flow_id'      => $flowGlobalId,
            'type'         => FlowTriggerType::Message,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['/start'],
                'phrases'  => [],
            ],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => self::ASSISTANT_ID,
            'flow_id'      => $flowAssistantId,
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
                tenantId: self::TENANT_ID,
                assistantId: self::ASSISTANT_ID,
                payload: ['text' => '/start'],
            )
        );

        $this->assertNotNull($trigger);
        $this->assertSame($flowAssistantId, $trigger->flowId);
    }

    public function test_it_rejects_empty_message_trigger_config(): void
    {
        $this->createAssistant(self::ASSISTANT_ID);

        $this->expectException(InvalidArgumentException::class);

        FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => self::ASSISTANT_ID,
            'flow_id'      => (string) Str::uuid(),
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
            'tenant_id' => self::TENANT_ID,
            'name'      => 'Test assistant',
            'is_active' => true,
            'settings'  => [],
        ]);
        $assistant->save();
    }
}
