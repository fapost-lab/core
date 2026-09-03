<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CreateFlowActionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('flow_drafts');
        Schema::create('flow_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id')->unique();
            $table->uuid('assistant_id');
            $table->uuid('flow_group_id')->nullable();
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('nodes')->nullable();
            $table->json('edges')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_creates_flow_draft_with_flow_group_id(): void
    {
        $tenantId = '00000000-0000-0000-0000-000000000001';
        $groupId  = '00000000-0000-0000-0000-000000000002';

        app()->instance(
            TenantContextInterface::class,
            new class ($tenantId) implements TenantContextInterface {
                public function __construct(private readonly string $tenantId)
                {
                }

                public function set(TenantInterface $tenant): void
                {
                }

                public function get(): TenantInterface
                {
                    return new class ($this->tenantId) implements TenantInterface {
                        public string $id;

                        public function __construct(string $tenantId)
                        {
                            $this->id = $tenantId;
                        }

                        public function getId(): string
                        {
                            return $this->id;
                        }

                        public function getSlug(): string
                        {
                            return 'main';
                        }

                        public function getSchemaName(): string
                        {
                            return 'main';
                        }

                        public function isActive(): bool
                        {
                            return true;
                        }

                        public function getConfig(string $key, mixed $default = null): mixed
                        {
                            return $default;
                        }
                    };
                }

                public function isResolved(): bool
                {
                    return true;
                }

                public function reset(): void
                {
                }
            }
        );

        $draft = app(CreateFlowAction::class)->execute([
            'assistant_id'  => '00000000-0000-0000-0000-000000000003',
            'flow_group_id' => $groupId,
            'name'          => 'Reset session',
            'description'   => 'Reset session in any place',
            'is_public'     => true,
        ]);

        $this->assertSame($tenantId, $draft->tenant_id);
        $this->assertSame($groupId, $draft->flow_group_id);
        $this->assertSame('Reset session', $draft->name);
        $this->assertDatabaseHas('flow_drafts', [
            'id'            => $draft->id,
            'flow_group_id' => $groupId,
        ]);

        $nodes = $draft->nodes;
        $this->assertIsArray($nodes);
        $this->assertCount(1, $nodes, 'A new draft is seeded with one end node.');

        $endNode = $nodes[0];
        $this->assertSame('end', $endNode['type']);
        $this->assertSame(1, $endNode['version']);
        $this->assertSame('success', $endNode['config']['status']);
        $this->assertNotEmpty($endNode['id']);

        $this->assertSame([], $draft->edges);
    }
}
