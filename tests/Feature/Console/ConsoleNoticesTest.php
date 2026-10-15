<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\DTO\AccessNotice;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * The two notices Filament showed as banners on every panel page, as the props the console shell draws its strips from,
 * on the real home screens of both modes: a stopped tenant's notice (`accessState`) and the operator behind a platform
 * support session (`supportAccess`, with the leave address in `shell`).
 */
final class ConsoleNoticesTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();
    }

    public function test_a_stopped_tenants_notice_reaches_the_admin_and_the_assistant_home_screens(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped(new AccessNotice(
            title: 'Your trial has ended',
            message: 'Renew to bring the assistant back.',
            actionLabel: 'Renew',
            actionUrl: 'https://billing.example.com/renew',
        )));

        $this->actingAs($this->admin());

        foreach ($this->homeScreens() as $url) {
            $this->get($this->panelUrl($url))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('accessState.mode', 'stopped')
                    ->where('accessState.notice', [
                        'title'       => 'Your trial has ended',
                        'message'     => 'Renew to bring the assistant back.',
                        'actionLabel' => 'Renew',
                        'actionUrl'   => 'https://billing.example.com/renew',
                    ])
                    ->etc());
        }
    }

    public function test_an_active_tenant_has_no_notice(): void
    {
        $this->actingAs($this->admin());

        foreach ($this->homeScreens() as $url) {
            $this->get($this->panelUrl($url))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('accessState', ['mode' => 'active', 'notice' => null])
                    ->etc());
        }
    }

    public function test_a_support_session_names_the_operator_on_both_home_screens_until_it_is_left(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $this->post(route('support.enter'), ['token' => $this->supportToken()])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        foreach ($this->homeScreens() as $url) {
            $this->get($this->panelUrl($url))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('supportAccess.operatorName', 'Olga')
                    ->where('supportAccess.operatorEmail', 'olga@example.com')
                    ->whereType('supportAccess.expiresAt', 'string')
                    ->where('shell.supportLeaveUrl', route('support.leave', [], false))
                    ->etc());
        }

        $this->post(route('support.leave'))->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest();
    }

    public function test_an_ordinary_session_has_no_support_notice_even_with_a_forged_record(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $this->actingAs($this->admin())->withSession([SupportAccessSession::KEY => [
            'entry_id'       => 'forged',
            'operator_name'  => 'Mallory',
            'operator_email' => 'mallory@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]]);

        foreach ($this->homeScreens() as $url) {
            $this->get($this->panelUrl($url))
                ->assertOk()
                ->assertDontSee('Mallory')
                ->assertInertia(fn (AssertableInertia $page) => $page->where('supportAccess', null)->etc());
        }
    }

    /**
     * The admin dashboard and the assistant dashboard.
     *
     * @return list<string>
     */
    private function homeScreens(): array
    {
        return ['/admin', "/assistant/{$this->assistant->getKey()}/dashboard"];
    }

    private function supportToken(): string
    {
        return $this->app->make(SupportAccessTokenStore::class)
            ->issue(new SupportAccessRequest(self::TENANT_ID, 'operator:7', 'Olga', 'olga@example.com'))['token'];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
