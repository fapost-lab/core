<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Exceptions\HandlerNotFoundException;
use App\Domains\Flow\Models\FlowDefinition;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class FlowEngineHandlerNotFoundTest extends FeatureTestCase
{
    public function test_start_throws_when_handler_is_not_registered(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Missing',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'not_registered_type', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->expectException(HandlerNotFoundException::class);

        $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
    }
}
