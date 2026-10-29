<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Mail\WorkspaceDeletionMail;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\Services\NeverAllowsSelfDeletion;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantSelfDeletionPolicyInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * An admin asking to delete the workspace from the tenant's settings: scheduled 24 hours ahead, announced to every
 * admin by mail, shown to admins as a banner and cancellable by any of them. Offered only in `host` mode, where the
 * operator's policy says yes, and never to the platform support user.
 */
final class AdminWorkspaceDeletionConsoleTest extends FeatureTestCase
{
    use RunsInHostMode;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionSecondTenant();
        $this->seed(TenantAclSeeder::class);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_core_s_default_policy_says_no(): void
    {
        $this->assertFalse((new NeverAllowsSelfDeletion())->allows(self::TENANT_ID));
    }

    public function test_where_the_policy_says_no_it_is_not_offered_and_the_endpoints_refuse(): void
    {
        $this->denySelfDeletion();
        $admin = $this->user(RoleEnum::Admin);

        $this->actingAs($admin)->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page->where('workspaceDeletion', null)->etc());
        $this->actingAs($admin)->post($this->url('/delete'), ['slug' => $this->slug()])->assertForbidden();

        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());
        Mail::assertNothingSent();
    }

    public function test_an_admin_is_offered_it_where_the_policy_allows(): void
    {
        $this->allowSelfDeletion();

        $this->actingAs($this->user(RoleEnum::Admin))
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspaceDeletion.slug', $this->slug())
                ->where('workspaceDeletion.canRequest', true)
                ->where('workspaceDeletion.requestUrl', '/admin/tenant-settings/delete')
                ->where('workspaceDeletion.cancelUrl', '/admin/tenant-settings/delete/cancel')
                ->where('workspaceDeletion.scheduled', null)
                ->etc());
    }

    public function test_someone_who_is_not_an_admin_is_refused_even_where_the_policy_allows(): void
    {
        $this->allowSelfDeletion();
        $manager = $this->user(RoleEnum::ContentManager);

        $this->actingAs($manager)->post($this->url('/delete'), ['slug' => $this->slug()])->assertForbidden();
        $this->actingAs($manager)->post($this->url('/delete/cancel'))->assertForbidden();

        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());
    }

    public function test_the_platform_support_user_can_neither_ask_nor_cancel_and_is_offered_nothing(): void
    {
        $this->allowSelfDeletion();
        config(['tenancy.support_access.enabled' => true]);
        $session = [SupportAccessSession::KEY => [
            'entry_id'       => 'entry-1',
            'operator_name'  => 'Olga',
            'operator_email' => 'olga@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]];
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($support)->withSession($session)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workspaceDeletion', null)->etc());
        $this->actingAs($support)->withSession($session)->post($this->url('/delete'), ['slug' => $this->slug()])->assertForbidden();
        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());

        // A request an admin made: support sees no banner and no section, and cannot cancel it.
        $this->actingAs($this->user(RoleEnum::Admin))->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();

        $this->actingAs($support)->withSession($session)->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaceDeletion', null)
            ->where('workspaceDeletionBanner', null)
            ->etc());
        $this->actingAs($support)->withSession($session)->post($this->url('/delete/cancel'))->assertForbidden();
        $this->assertNull(TenantDeletionRequest::on('landlord')->firstOrFail()->cancelled_at);
    }

    public function test_a_wrong_or_missing_slug_is_rejected_and_nothing_is_recorded(): void
    {
        $this->allowSelfDeletion();
        $admin = $this->user(RoleEnum::Admin);

        $this->actingAs($admin)
            ->post($this->url('/delete'), ['slug' => $this->slug() . '-x'])
            ->assertSessionHasErrors(['slug' => trans('console.tenant_settings.delete.slug_mismatch')]);
        $this->actingAs($admin)->post($this->url('/delete'), [])->assertSessionHasErrors('slug');

        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());
        Mail::assertNothingSent();
    }

    public function test_outside_host_mode_it_is_refused_whatever_the_policy_says(): void
    {
        $this->allowSelfDeletion();
        config(['tenancy.resolution' => 'single']);
        $admin = $this->user(RoleEnum::Admin);

        $this->actingAs($admin)->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page->where('workspaceDeletion', null)->etc());
        $this->actingAs($admin)->post($this->url('/delete'), ['slug' => $this->slug()])->assertForbidden();

        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());
    }

    public function test_asking_schedules_it_in_24_hours_stays_signed_in_and_mails_every_admin_only(): void
    {
        $this->allowSelfDeletion();
        $asker = $this->user(RoleEnum::Admin, 'Ann Asker');
        $other = $this->user(RoleEnum::Admin);
        $gone  = $this->user(RoleEnum::Admin);
        $gone->forceFill(['is_active' => false])->save();
        $this->user(RoleEnum::ContentManager);
        $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($asker)
            ->post($this->url('/delete'), ['slug' => ' ' . $this->slug() . ' '])
            ->assertRedirect()
            ->assertInertiaFlash('success', trans('console.tenant_settings.delete.scheduled'));

        $this->assertAuthenticatedAs($asker);
        $request = TenantDeletionRequest::on('landlord')->firstOrFail();
        $this->assertSame(self::TENANT_ID, $request->tenant_id);
        $this->assertSame($asker->getKey(), $request->requested_by_user_id);
        $this->assertSame('Ann Asker', $request->requested_by_name);
        $this->assertSame($asker->email, $request->requested_by_email);
        $this->assertEqualsWithDelta(24 * 3600, $request->starts_at->diffInSeconds($request->requested_at, true), 2);

        $recipients = [];

        Mail::assertSent(WorkspaceDeletionMail::class, function (WorkspaceDeletionMail $mail) use (&$recipients): bool {
            $recipients[] = $mail->to[0]['address'];

            return WorkspaceDeletionMail::SCHEDULED === $mail->kind;
        });
        sort($recipients);
        $expected = [$asker->email, $other->email];
        sort($expected);
        $this->assertSame($expected, $recipients, 'Only the active admins are mailed: no manager, no support user, no deactivated admin.');

        Mail::assertSent(WorkspaceDeletionMail::class, function (WorkspaceDeletionMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo($mail->to[0]['address'])
                && str_contains($html, $this->slug())
                && str_contains($html, 'Ann Asker')
                && str_contains($html, 'UTC')
                && str_contains($html, trans('staff.workspace_deletion.scheduled.irreversible'))
                && str_contains($html, '/admin/tenant-settings');
        });
    }

    public function test_a_second_request_while_one_is_active_answers_that_it_is_already_scheduled(): void
    {
        $this->allowSelfDeletion();
        $admin = $this->user(RoleEnum::Admin);

        $this->actingAs($admin)->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();

        $this->actingAs($admin)
            ->post($this->url('/delete'), ['slug' => $this->slug()])
            ->assertRedirect()
            ->assertInertiaFlash('error', trans('console.tenant_settings.delete.already_scheduled'));

        $this->assertSame(1, TenantDeletionRequest::on('landlord')->count());
        Mail::assertSent(WorkspaceDeletionMail::class, 1);
    }

    public function test_a_notice_that_cannot_be_queued_leaves_nothing_scheduled(): void
    {
        $this->allowSelfDeletion();
        $admin = $this->user(RoleEnum::Admin);

        Event::listen(WorkspaceDeletionScheduled::class, static function (): never {
            throw new RuntimeException('The queue is down.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post($this->url('/delete'), ['slug' => $this->slug()]);
            $this->fail('The refused notice should have surfaced.');
        } catch (RuntimeException $e) {
            $this->assertSame('The queue is down.', $e->getMessage());
        }

        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());
    }

    public function test_the_banner_and_the_section_show_the_scheduled_deletion_to_admins_only(): void
    {
        $this->allowSelfDeletion();
        $asker = $this->user(RoleEnum::Admin, 'Ann Asker');
        $this->actingAs($asker)->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();
        $request = TenantDeletionRequest::on('landlord')->firstOrFail();

        $this->actingAs($this->user(RoleEnum::Admin))
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('workspaceDeletionBanner.requestedBy', 'Ann Asker')
                ->where('workspaceDeletionBanner.startsAt', $request->starts_at->toIso8601ZuluString())
                ->where('workspaceDeletionBanner.cancelUrl', '/admin/tenant-settings/delete/cancel')
                ->where('workspaceDeletion.scheduled.requestedBy', 'Ann Asker')
                ->etc());

        $this->actingAs($this->user(RoleEnum::ContentManager))
            ->get($this->panelAdminUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workspaceDeletionBanner', null)->etc());
    }

    public function test_another_admin_cancels_it_and_every_admin_is_told_who(): void
    {
        $this->allowSelfDeletion();
        $asker     = $this->user(RoleEnum::Admin, 'Ann Asker');
        $canceller = $this->user(RoleEnum::Admin, 'Carl Canceller');
        $this->actingAs($asker)->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();
        Mail::fake();

        $this->actingAs($canceller)
            ->post($this->url('/delete/cancel'))
            ->assertRedirect()
            ->assertInertiaFlash('success', trans('console.tenant_settings.delete.cancelled'));

        $request = TenantDeletionRequest::on('landlord')->firstOrFail();
        $this->assertNotNull($request->cancelled_at);
        $this->assertSame($canceller->getKey(), $request->cancelled_by_user_id);
        $this->assertSame('Carl Canceller', $request->cancelled_by_name);

        Mail::assertSent(WorkspaceDeletionMail::class, 2);
        Mail::assertSent(WorkspaceDeletionMail::class, fn (WorkspaceDeletionMail $mail): bool => WorkspaceDeletionMail::CANCELLED === $mail->kind
            && str_contains($mail->render(), 'Carl Canceller'));

        // Cancelled: no banner any more, and it can be asked for again.
        $this->actingAs($asker)->get($this->url())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaceDeletionBanner', null)
            ->where('workspaceDeletion.scheduled', null)
            ->etc());
        $this->actingAs($asker)->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();
        $this->assertSame(2, TenantDeletionRequest::on('landlord')->count());
    }

    public function test_cancelling_with_nothing_scheduled_says_so(): void
    {
        $this->actingAs($this->user(RoleEnum::Admin))
            ->post($this->url('/delete/cancel'))
            ->assertRedirect()
            ->assertInertiaFlash('error', trans('console.tenant_settings.delete.nothing_to_cancel'));

        Mail::assertNothingSent();
    }

    public function test_the_admins_of_a_stopped_tenant_can_ask_and_cancel(): void
    {
        $this->allowSelfDeletion();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $admin = $this->user(RoleEnum::Admin);

        $this->actingAs($admin)->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();
        $this->assertSame(1, TenantDeletionRequest::on('landlord')->count());

        $this->actingAs($admin)->post($this->url('/delete/cancel'))->assertRedirect();
        $this->assertNotNull(TenantDeletionRequest::on('landlord')->firstOrFail()->cancelled_at);
    }

    public function test_deleting_the_tenant_takes_its_requests_with_it(): void
    {
        $this->allowSelfDeletion();
        $this->actingAs($this->user(RoleEnum::Admin))->post($this->url('/delete'), ['slug' => $this->slug()])->assertRedirect();

        $this->app->make(TenantRepositoryInterface::class)->delete(Tenant::on('landlord')->findOrFail(self::TENANT_ID));

        $this->assertSame(0, TenantDeletionRequest::on('landlord')->count());
    }

    private function denySelfDeletion(): void
    {
        $this->app->instance(TenantSelfDeletionPolicyInterface::class, new NeverAllowsSelfDeletion());
    }

    private function allowSelfDeletion(): void
    {
        $this->app->instance(TenantSelfDeletionPolicyInterface::class, new class () implements TenantSelfDeletionPolicyInterface {
            public function allows(string $tenantId): bool
            {
                return true;
            }
        });
    }

    private function user(RoleEnum $role, ?string $name = null): User
    {
        $user = User::factory()->create(null === $name ? [] : ['name' => $name]);
        $user->assignRole($role->value);

        return $user;
    }

    private function slug(): string
    {
        return Tenant::on('landlord')->findOrFail(self::TENANT_ID)->slug;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelAdminUrl('/admin/tenant-settings' . $suffix);
    }

    private function panelAdminUrl(string $path): string
    {
        return 'http://' . $this->slug() . '.' . config('tenancy.base_domain') . $path;
    }
}
