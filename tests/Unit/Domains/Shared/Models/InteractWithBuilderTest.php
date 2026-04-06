<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Shared\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Builders\AssistantBuilder;
use App\Domains\Flow\Models\Builders\FlowDefinitionBuilder;
use App\Domains\Flow\Models\FlowDefinition;
use Tests\Feature\FeatureTestCase;

final class InteractWithBuilderTest extends FeatureTestCase
{
    public function test_assistant_uses_custom_builder_with_active_method(): void
    {
        $query = Assistant::query()->active();

        $this->assertInstanceOf(AssistantBuilder::class, $query);
        $this->assertStringContainsString('is_active', $query->toSql());
    }

    public function test_flow_definition_uses_custom_builder_with_active_method(): void
    {
        $query = FlowDefinition::query()->active();

        $this->assertInstanceOf(FlowDefinitionBuilder::class, $query);
        $this->assertStringContainsString('is_active', $query->toSql());
    }
}
