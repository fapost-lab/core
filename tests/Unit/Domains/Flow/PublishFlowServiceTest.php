<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class PublishFlowServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('media_file_references');
        Schema::dropIfExists('flow_definitions');
        Schema::dropIfExists('flow_drafts');

        Schema::create('media_file_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('media_file_id');
            $table->string('reference_type', 32);
            $table->uuid('reference_id');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index('media_file_id');
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('flow_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id')->unique();
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->json('nodes')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->json('nodes');
            $table->json('edges')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['flow_id', 'version']);
        });

        $this->app->bind(DataAccessorRegistryInterface::class, static function (): DataAccessorRegistryInterface {
            return new class () implements DataAccessorRegistryInterface {
                public function has(string $namespacePrefix): bool
                {
                    return false;
                }

                public function resolve(string $namespacePrefix): \FAPost\Foundation\Contracts\DataAccessorInterface
                {
                    throw new LogicException('Not needed in this test.');
                }
            };
        });
    }

    public function test_assigns_unique_versions_for_repeated_publish_calls(): void
    {
        $flowId = '00000000-0000-0000-0000-000000000321';

        FlowDraft::factory()->create([
            'flow_id' => $flowId,
            'nodes'   => [
                [
                    'id'      => 'start',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [],
                ],
            ],
        ]);

        $service = app(PublishFlowService::class);
        $first   = $service->execute($flowId);
        $second  = $service->execute($flowId);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertTrue($second->is_active);
        $this->assertFalse((bool) FlowDefinition::query()->findOrFail($first->id)->is_active);
    }

    public function test_throws_flow_validation_exception_when_draft_is_invalid(): void
    {
        $flowId = '00000000-0000-0000-0000-000000000654';

        FlowDraft::factory()->create([
            'flow_id' => $flowId,
            'nodes'   => [
                [
                    'id'      => 'condition_1',
                    'type'    => 'condition',
                    'version' => 1,
                    'config'  => ['check' => 'flow.answer'],
                    'outputs' => [
                        'yes' => ['next' => 'missing-node'],
                    ],
                ],
            ],
        ]);

        $service = app(PublishFlowService::class);

        try {
            $service->execute($flowId);
            $this->fail('Expected FlowValidationException was not thrown.');
        } catch (FlowValidationException $exception) {
            $this->assertNotEmpty($exception->errors);
            $this->assertContainsOnlyInstancesOf(FlowValidationErrorDto::class, $exception->errors);
        }
    }
}
