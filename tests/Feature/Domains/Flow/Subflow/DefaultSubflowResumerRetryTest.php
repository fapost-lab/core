<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Subflow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Subflow\DefaultSubflowResumer;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\FeatureTestCase;

/**
 * A parent whose resume save loses the optimistic lock is retried once, but only while it still waits on the
 * same subflow node; a conflict from its own resumed run propagates without a second resume.
 */
final class DefaultSubflowResumerRetryTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000002';

    private FlowSession $parent;

    private FlowSession $child;

    protected function setUp(): void
    {
        parent::setUp();

        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $flow      = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Retry flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $base = [
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'state'              => [],
            'version'            => 1,
        ];

        $this->parent = FlowSession::query()->create($base + ['current_node_id' => 'p-sub', 'status' => FlowSessionStatus::PausedSubflow]);
        $this->child  = FlowSession::query()->create($base + [
            'current_node_id'   => null,
            'status'            => FlowSessionStatus::Ended,
            'end_status'        => 'success',
            'parent_session_id' => $this->parent->getKey(),
        ]);
    }

    public function test_a_lost_resume_save_is_retried_once_while_the_parent_still_waits_on_the_subflow_node(): void
    {
        $engine = Mockery::mock(FlowEngineInterface::class);
        $calls  = 0;
        $parent = $this->parent;
        $engine->shouldReceive('resumeAfterSubflow')
            ->twice()
            ->andReturnUsing(static function () use (&$calls, $parent): FlowSession {
                if (1 === ++$calls) {
                    throw FlowConcurrencyException::forSession('x');
                }

                return $parent;
            });

        $this->resumer($engine)->resumeIfChild($this->child, EndStatus::Success->value);
    }

    public function test_a_conflict_from_the_parents_resumed_run_propagates_without_a_second_resume(): void
    {
        $parent = $this->parent;

        $engine = Mockery::mock(FlowEngineInterface::class);
        $engine->shouldReceive('resumeAfterSubflow')
            ->once()
            ->andReturnUsing(function () use ($parent): never {
                // The parent was saved and moved on before its own run conflicted.
                FlowSession::query()->whereKey($parent->getKey())->update([
                    'status'          => FlowSessionStatus::WaitingInput->value,
                    'current_node_id' => 'p-next',
                ]);

                throw FlowConcurrencyException::forSession('x');
            });

        $this->expectException(FlowConcurrencyException::class);

        $this->resumer($engine)->resumeIfChild($this->child, EndStatus::Success->value);
    }

    private function resumer(FlowEngineInterface $engine): DefaultSubflowResumer
    {
        return $this->app->make(DefaultSubflowResumer::class, ['engineResolver' => static fn (): FlowEngineInterface => $engine]);
    }
}
