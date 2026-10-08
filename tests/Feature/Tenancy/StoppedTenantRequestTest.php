<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Services\CurrentAccessState;
use App\Filament\Support\AccessNoticeBanner;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\DTO\AccessNotice;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * A stopped tenant on the request side: writes outside Filament answer 423, reads and the
 * validate endpoint carry on, platform support may write, the notice reaches the banner and the
 * Inertia props, and the operator is asked once per request.
 */
final class StoppedTenantRequestTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private FakeTenantAccessMode $mode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->mode = FakeTenantAccessMode::stopped(new AccessNotice(
            title: 'Your trial has ended',
            message: 'Renew to bring the assistant back.',
            actionLabel: 'Renew',
            actionUrl: 'https://billing.example.com/renew',
        ));
        $this->app->instance(TenantAccessModeInterface::class, $this->mode);
    }

    public function test_an_active_tenant_changes_nothing(): void
    {
        $this->mode->resume();

        $this->actingAs($this->admin())->postJson('/media/folders', ['name' => 'Projects'])->assertCreated();
    }

    public function test_a_media_write_is_refused_with_the_notice(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/media/folders', ['name' => 'Projects'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'tenant_stopped')
            ->assertJsonPath('message', 'Renew to bring the assistant back.')
            ->assertJsonPath('notice.title', 'Your trial has ended')
            ->assertJsonPath('notice.actionUrl', 'https://billing.example.com/renew');

        $this->assertDatabaseCount('media_folders', 0);
    }

    public function test_every_unsafe_method_is_refused_and_a_notice_free_stop_gets_the_default_text(): void
    {
        $this->mode->state = FakeTenantAccessMode::stopped()->state;
        $admin             = $this->admin();
        $folder            = MediaFolder::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'Existing', 'path_cache' => '/Existing']);

        $this->actingAs($admin)->patchJson("/media/folders/{$folder->getKey()}", [])->assertStatus(423)
            ->assertJsonPath('message', __('tenancy.stopped.message'))
            ->assertJsonPath('notice', null);
        $this->actingAs($admin)->deleteJson("/media/folders/{$folder->getKey()}")->assertStatus(423);
        $draft = $this->draft();
        $this->actingAs($admin)->putJson("/builder/flows/{$draft->flow_id}/draft", [])->assertStatus(423);
        $this->actingAs($admin)->postJson("/builder/flows/{$draft->flow_id}/publish", [])->assertStatus(423);
    }

    public function test_the_mini_app_submit_is_refused_and_its_read_is_not(): void
    {
        $this->postJson('/tma/api/forms/form-1/submit', ['answers' => []])->assertStatus(423);
        $this->getJson('/tma/api/forms/form-1')->assertOk();
    }

    public function test_reads_and_validation_carry_on(): void
    {
        $draft = $this->draft();
        $admin = $this->admin();

        $this->actingAs($admin)->get("/builder/flows/{$draft->flow_id}")->assertOk();
        $this->actingAs($admin)->getJson('/media/folders')->assertOk();
        $this->actingAs($admin)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", ['definition' => ['nodes' => []]])
            ->assertOk();
    }

    public function test_platform_support_may_write_in_a_stopped_tenant(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($support)->withSession([SupportAccessSession::KEY => [
            'entry_id'       => 'entry-1',
            'operator_name'  => 'Olga',
            'operator_email' => 'olga@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]])->postJson('/media/folders', ['name' => 'Fixed by support'])->assertCreated();
    }

    public function test_the_operator_is_asked_once_per_request_for_this_tenant(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->admin())->get("/builder/flows/{$draft->flow_id}")->assertOk();

        $this->assertSame([self::TENANT_ID], $this->mode->asked);
    }

    public function test_an_operator_that_throws_leaves_the_request_unchanged_and_is_reported(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, new class () implements TenantAccessModeInterface {
            public function stateFor(string $tenantId): \Fapost\Foundation\Tenancy\DTO\TenantAccessState
            {
                throw new RuntimeException('operator is down');
            }
        });
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $this->actingAs($this->admin())->postJson('/media/folders', ['name' => 'Projects'])->assertCreated();

        $this->assertSame(['operator is down'], $reported);
    }

    public function test_the_state_does_not_outlive_the_request(): void
    {
        $this->actingAs($this->admin())->getJson('/media/folders')->assertOk();

        $this->assertFalse($this->app->make(CurrentAccessState::class)->get()->isStopped());
    }

    public function test_the_builder_page_shares_the_access_state(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->admin())
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertInertia(fn ($page) => $page
                ->where('accessState.mode', 'stopped')
                ->where('accessState.notice.title', 'Your trial has ended')
                ->where('accessState.notice.actionLabel', 'Renew')
                ->etc());
    }

    public function test_the_panel_banner_shows_the_notice(): void
    {
        $this->actingAs($this->admin())
            ->get(route('filament.admin.pages.dashboard'))
            ->assertOk()
            ->assertSee('Your trial has ended')
            ->assertSee('Renew to bring the assistant back.')
            ->assertSee('https://billing.example.com/renew');
    }

    public function test_the_banner_is_empty_without_a_notice(): void
    {
        $this->assertSame('', AccessNoticeBanner::render());
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function draft(): FlowDraft
    {
        $assistant = Assistant::factory()->create();

        return FlowDraft::factory()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
        ]);
    }
}
