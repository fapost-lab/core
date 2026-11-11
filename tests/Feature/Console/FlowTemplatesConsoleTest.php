<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\TenantFlowActivity;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * Flow templates on the Inertia console: a list apart from the flows, creating one (which ends in the builder, under the
 * flow limit) and deleting one, which is refused while a flow still places it.
 */
final class FlowTemplatesConsoleTest extends FeatureTestCase
{
    use SetsRecordLimits;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();
    }

    public function test_the_list_shows_only_the_templates_and_the_flows_list_shows_no_template(): void
    {
        $template = $this->template('Greeting part');
        $host     = $this->host($template);
        FlowDefinition::query()->create([
            'tenant_id' => $template->tenant_id,
            'flow_id'   => $template->flow_id,
            'version'   => 2,
            'name'      => $template->name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);
        $this->template('Elsewhere', Assistant::factory()->create());

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/FlowTemplates/Index')
                ->where('table.meta.total', 1)
                ->where('table.rows.0', [
                    'id'               => (string) $template->getKey(),
                    'flowId'           => $template->flow_id,
                    'name'             => 'Greeting part',
                    'description'      => null,
                    'publishedVersion' => 2,
                    'usedBy'           => 1,
                    'deleteUrl'        => "/assistant/{$this->assistant->getKey()}/flow-templates/{$template->getKey()}",
                    'builderUrl'       => "/builder/flows/{$template->flow_id}",
                ])
                ->where('can', ['create' => true, 'delete' => true])
                ->where('urls.create', "/assistant/{$this->assistant->getKey()}/flow-templates/create")
                ->etc());

        $this->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/flows"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.flowId', $host->flow_id)
                ->etc());
    }

    public function test_the_create_screen_opens_below_the_limit(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Console/FlowTemplates/Create')->etc());
    }

    public function test_the_create_screen_is_closed_at_the_limit(): void
    {
        $this->limitRecords('flows', 1);
        $this->template('Takes the last slot');

        $this->actingAs($this->admin())->get($this->url('/create'))->assertForbidden();
    }

    public function test_creating_seeds_a_template_with_an_output_and_ends_in_the_builder(): void
    {
        $response = $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'Shared part', 'description' => 'Asks for a phone'], ['X-Inertia' => 'true']);

        $template = FlowDraft::query()->where('name', 'Shared part')->sole();

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', "/builder/flows/{$template->flow_id}");

        $this->assertSame(FlowKind::Template, $template->kind);
        $this->assertSame((string) $this->assistant->getKey(), $template->assistant_id);
        $this->assertSame('Asks for a phone', $template->description);
        $this->assertSame('template_output', $template->nodes[0]['type']);
    }

    public function test_creating_validates_the_name_and_the_description(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->url(), ['name' => ''])->assertSessionHasErrors('name');
        $this->post($this->url(), ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->post($this->url(), ['name' => 'Ok', 'description' => str_repeat('a', 65536)])->assertSessionHasErrors('description');

        $this->assertSame(0, FlowDraft::query()->count());
    }

    public function test_templates_count_under_the_flow_limit(): void
    {
        $this->limitRecords('flows', 1);
        $this->template('Only one');
        $from = $this->url('/create');

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('limit.reached', true)->where('can.create', false)->etc());

        $this->from($from)
            ->post($this->url(), ['name' => 'Late'])
            ->assertRedirect($from)
            ->assertInertiaFlash('error', 'Flow limit reached. Flows limit reached: 1 of 1.');

        $this->assertSame(1, FlowDraft::query()->count());
    }

    public function test_an_unused_template_is_deleted(): void
    {
        $template = $this->template('Unused');

        $this->actingAs($this->admin())
            ->delete($this->url("/{$template->getKey()}"))
            ->assertRedirect()
            ->assertInertiaFlash('success', 'Template deleted.');

        $this->assertNull(FlowDraft::query()->find($template->getKey()));
    }

    public function test_a_template_placed_in_a_flow_cannot_be_deleted(): void
    {
        $template = $this->template('Used');
        $this->host($template);

        $this->actingAs($this->admin())
            ->delete($this->url("/{$template->getKey()}"))
            ->assertRedirect()
            ->assertInertiaFlash('error', 'Cannot delete: a flow uses this template. Remove it from the flow first.');

        $this->assertNotNull(FlowDraft::query()->find($template->getKey()));
    }

    public function test_a_flow_is_not_found_on_the_templates_screen(): void
    {
        $flow = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey()]);

        $this->actingAs($this->admin())
            ->delete($this->url("/{$flow->getKey()}"))
            ->assertNotFound();

        $this->assertNotNull(FlowDraft::query()->find($flow->getKey()));
    }

    public function test_deleting_many_counts_the_blocked_ones_and_leaves_flows_alone(): void
    {
        $free  = $this->template('Free');
        $used  = $this->template('Used');
        $other = $this->template('Another free');
        $this->host($used);
        $flow = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey()]);

        $this->actingAs($this->admin())
            ->delete($this->url(), ['ids' => [(string) $free->getKey(), (string) $used->getKey(), (string) $flow->getKey()]])
            ->assertRedirect()
            ->assertInertiaFlash('success', 'Templates deleted: 1. Skipped because flows use them: 1.');

        $this->assertNull(FlowDraft::query()->find($free->getKey()));
        $this->assertNotNull(FlowDraft::query()->find($used->getKey()));
        $this->assertNotNull(FlowDraft::query()->find($other->getKey()));
        $this->assertNotNull(FlowDraft::query()->find($flow->getKey()));

        $this->delete($this->url(), ['ids' => [(string) $used->getKey()]])
            ->assertInertiaFlash('error', 'Cannot delete: a flow uses this template. Remove it from the flow first.');

        $this->delete($this->url(), ['ids' => [(string) $other->getKey()]])
            ->assertInertiaFlash('success', 'Templates deleted: 1.');
    }

    public function test_published_versions_of_a_template_are_not_counted_as_published_flows(): void
    {
        $template = $this->template('Shared');
        $flow     = FlowDraft::factory()->create(['assistant_id' => $this->assistant->getKey()]);

        foreach ([$template, $flow] as $draft) {
            FlowDefinition::query()->create([
                'tenant_id' => $draft->tenant_id,
                'flow_id'   => $draft->flow_id,
                'version'   => 1,
                'name'      => $draft->name,
                'nodes'     => [],
                'edges'     => [],
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->admin());
        $this->app->make(TenantContextInterface::class)->set($this->tenant());

        $this->assertSame(1, $this->app->make(TenantFlowActivity::class)->publishedFlows());
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flow-templates{$suffix}");
    }

    private function template(string $name, ?Assistant $assistant = null): FlowDraft
    {
        return FlowDraft::factory()->template()->create([
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'name'         => $name,
        ]);
    }

    /**
     * A flow whose draft places the template as one `template` node.
     */
    private function host(FlowDraft $template): FlowDraft
    {
        return FlowDraft::factory()->create([
            'assistant_id' => $template->assistant_id,
            'name'         => 'Host',
            'nodes'        => [['id' => 'tpl-1', 'type' => 'template', 'version' => 1, 'config' => ['template_flow_id' => $template->flow_id]]],
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
