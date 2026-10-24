<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelWebhookStatus;
use App\Domains\Channels\Models\Channel;
use App\Domains\Onboarding\Services\OnboardingProgress;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Settings\OnboardingSettings;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * The optional steps of the setup wizard, connecting a channel and inviting the team: they run once the required steps
 * are done, can be skipped without writing anything, and reuse the channel and invitation services of the console.
 */
final class OnboardingOptionalStepsTest extends FeatureTestCase
{
    use InteractsWithOnboarding;
    use SetsRecordLimits;

    private const string TOKEN = 'bot-token-SECRET-111';

    private const string SECRET = 'webhook-secret-SECRET-222';

    private Assistant $assistant;

    private Dispatcher $realBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        // Saving a channel registers its webhook: recorded here instead of written to Redis. The channel observer is
        // built once per model boot, so the fake has to be there before it is.
        $this->app->instance(ChannelWebhookRegistryInterface::class, new class () implements ChannelWebhookRegistryInterface {
            public function set(Channel $channel, TenantInterface $tenant): void
            {
            }

            public function remove(string $webhookPublicHash): void
            {
            }

            public function warmup(TenantInterface $tenant): void
            {
            }
        });
        Model::clearBootedModels();

        $this->realBus = Bus::getFacadeRoot();
        Bus::fake([SyncChannelWebhookJob::class, SendActivationEmailJob::class]);

        $this->assistant = Assistant::factory()->create();
        $this->app->make(OnboardingState::class)->complete();
    }

    public function test_the_channel_page_carries_the_props_of_its_contract_and_no_secret(): void
    {
        $response = $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/onboarding/channel'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Onboarding/Channel')
                ->where('onboarding.steps', [
                    ['key' => 'languages', 'required' => true, 'complete' => false, 'current' => false],
                    ['key' => 'first_assistant', 'required' => true, 'complete' => true, 'current' => false],
                    ['key' => 'channel', 'required' => false, 'complete' => false, 'current' => true],
                    ['key' => 'team', 'required' => false, 'complete' => false, 'current' => false],
                ])
                ->where('types.0.value', 'telegram')
                ->has('settings.telegram')
                ->where('later', trans('console.onboarding.channel.later', ['path' => 'Channels › Channels']))
                ->where('urls', ['submit' => '/admin/onboarding/channel', 'skip' => '/admin/onboarding/continue/channel'])
                ->etc());

        $this->assertStringNotContainsString('token', mb_strtolower(json_encode($response->viewData('page')['props']['urls'], JSON_THROW_ON_ERROR)));
    }

    public function test_the_optional_pages_send_the_person_back_while_a_required_step_is_open(): void
    {
        $this->app->make(OnboardingState::class)->start();
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/channel'))->assertRedirect(route('console.admin.onboarding.show'));
        $this->actingAs($admin)->post($this->panelUrl('/admin/onboarding/channel'), $this->telegramPayload())->assertRedirect(route('console.admin.onboarding.show'));
        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/team'))->assertRedirect(route('console.admin.onboarding.show'));
        $this->actingAs($admin)->post($this->panelUrl('/admin/onboarding/team'), $this->invitation('a@example.test'))->assertRedirect(route('console.admin.onboarding.show'));
        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/continue/channel'))->assertRedirect(route('console.admin.onboarding.show'));

        $this->assertSame(0, Channel::query()->count());
        $this->assertFalse(User::query()->where('email', 'a@example.test')->exists());
    }

    public function test_connecting_a_channel_stores_it_for_the_first_assistant_and_moves_to_the_team_step(): void
    {
        $this->actingAs($this->admin())
            ->post($this->panelUrl('/admin/onboarding/channel'), $this->telegramPayload())
            ->assertRedirect(route('console.admin.onboarding.team.create'))
            ->assertInertiaFlash('success', trans('console.channels.created'));

        $channel = Channel::query()->sole();
        $this->assertSame((string) $this->assistant->getKey(), $channel->assistant_id);
        $this->assertSame(self::TOKEN, $channel->token);
        $this->assertTrue($this->stepIsComplete('channel'));
    }

    public function test_a_channel_is_validated_like_the_console_form(): void
    {
        $this->actingAs($this->admin())
            ->post($this->panelUrl('/admin/onboarding/channel'), [])
            ->assertSessionHasErrors(['type', 'token', 'secret_token', 'is_active']);
        $this->actingAs($this->admin())
            ->post($this->panelUrl('/admin/onboarding/channel'), $this->telegramPayload(['type' => 'carrier-pigeon']))
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_a_provider_that_refuses_is_worded_without_the_exception_and_the_wizard_goes_on(): void
    {
        Bus::swap($this->realBus);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $response = $this->actingAs($this->admin())
            ->post($this->panelUrl('/admin/onboarding/channel'), $this->telegramPayload())
            ->assertRedirect(route('console.admin.onboarding.team.create'))
            ->assertInertiaFlash('error', trans('console.channels.provider_failed.saved'));

        $this->assertSame(ChannelWebhookStatus::Failed, Channel::query()->sole()->webhook_status);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_at_the_channel_limit_the_step_is_not_shown_and_the_wizard_goes_on(): void
    {
        $this->limitRecords('channels', 0);
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/channel'))->assertRedirect(route('console.admin.onboarding.team.create'));
        $this->actingAs($admin)
            ->post($this->panelUrl('/admin/onboarding/channel'), $this->telegramPayload())
            ->assertRedirect(route('console.admin.onboarding.team.create'))
            ->assertInertiaFlash('error');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_staff_who_may_not_create_channels_are_passed_on_from_the_channel_page(): void
    {
        $manager = $this->manager();
        $manager->assistants()->attach($this->assistant);

        $this->actingAs($manager)->get($this->panelUrl('/admin/onboarding/channel'))->assertRedirect(route('console.admin.onboarding.team.create'));
    }

    public function test_staff_who_may_not_create_channels_are_refused_on_store(): void
    {
        $manager = $this->manager();
        $manager->assistants()->attach($this->assistant);

        $this->actingAs($manager)->post($this->panelUrl('/admin/onboarding/channel'), $this->telegramPayload())->assertForbidden();

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_a_user_who_may_open_no_assistant_is_passed_on_from_the_channel_step(): void
    {
        $this->actingAs($this->manager())->get($this->panelUrl('/admin/onboarding/channel'))->assertRedirect(route('console.admin.onboarding.team.create'));
    }

    public function test_skipping_walks_on_to_the_console_and_writes_nothing(): void
    {
        $admin    = $this->admin();
        $state    = $this->app->make(OnboardingState::class);
        $settings = (new OnboardingSettings())->toArray();
        $steps    = fn (): array => $this->app->make(OnboardingProgress::class)->report()->steps;

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/continue/channel'))->assertRedirect(route('console.admin.onboarding.team.create'));
        $this->actingAs($admin)
            ->get($this->panelUrl('/admin/onboarding/continue/team'))
            ->assertRedirect(route('console.entry'))
            ->assertInertiaFlash('success', trans('console.onboarding.done'));

        $this->assertSame($settings, (new OnboardingSettings())->toArray());
        $this->assertSame([], $state->confirmedSteps());
        $this->assertFalse($this->stepIsComplete('channel'), 'A skipped step stays open in the checklist.');
        $this->assertFalse($this->stepIsComplete('team'));
        $this->assertNotEmpty($steps());
    }

    public function test_skipping_the_channel_goes_past_a_team_that_is_already_there(): void
    {
        $this->staff();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/onboarding/continue/channel'))
            ->assertRedirect(route('console.entry'));
    }

    public function test_a_step_nobody_registered_is_a_404(): void
    {
        $this->actingAs($this->admin())->get($this->panelUrl('/admin/onboarding/continue/nonsense'))->assertNotFound();
    }

    public function test_continuing_needs_the_right_to_manage_settings(): void
    {
        $this->actingAs($this->manager())->get($this->panelUrl('/admin/onboarding/continue/channel'))->assertForbidden();
    }

    public function test_the_platform_support_user_is_not_the_team_but_an_invitation_is(): void
    {
        $admin = $this->admin();
        $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->assertFalse($this->stepIsComplete('team'));

        $this->actingAs($admin)
            ->post($this->panelUrl('/admin/onboarding/team'), $this->invitation('new@example.test'))
            ->assertRedirect(route('console.admin.onboarding.team.create'))
            ->assertInertiaFlash('success', trans('console.users.invited'));

        $this->assertTrue($this->stepIsComplete('team'));
        Bus::assertDispatched(SendActivationEmailJob::class);
    }

    public function test_the_team_page_offers_the_roles_and_turns_skip_into_continue_once_someone_is_invited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin/onboarding/team'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Onboarding/Team')
                ->where('onboarding.steps.3', ['key' => 'team', 'required' => false, 'complete' => false, 'current' => true])
                ->where('complete', false)
                ->where('later', trans('console.onboarding.team.later', ['path' => 'Staff › Users']))
                ->where('urls', ['submit' => '/admin/onboarding/team', 'skip' => '/admin/onboarding/continue/team'])
                ->has('roles')
                ->etc());

        $this->staff();

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin/onboarding/team'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('complete', true)->etc());
    }

    public function test_an_invitation_is_validated_and_a_role_above_the_actor_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post($this->panelUrl('/admin/onboarding/team'), ['name' => '', 'email' => 'not-an-email', 'phone' => '', 'role_id' => 'nope'])
            ->assertSessionHasErrors(['name', 'email', 'role_id']);

        $this->assertSame(1, User::query()->withoutPlatformSupport()->count());
    }

    public function test_at_the_staff_limit_the_team_step_is_not_shown_and_the_wizard_goes_on(): void
    {
        $admin = $this->admin();
        $this->limitRecords('staff', 1);

        $this->actingAs($admin)->get($this->panelUrl('/admin/onboarding/team'))->assertRedirect(route('console.entry'));
        $this->actingAs($admin)
            ->post($this->panelUrl('/admin/onboarding/team'), $this->invitation('late@example.test'))
            ->assertRedirect(route('console.entry'))
            ->assertInertiaFlash('error');

        $this->assertFalse(User::query()->where('email', 'late@example.test')->exists());
    }

    public function test_staff_who_may_not_invite_are_passed_on_from_the_team_page(): void
    {
        $this->actingAs($this->manager())->get($this->panelUrl('/admin/onboarding/team'))->assertRedirect(route('console.entry'));
    }

    public function test_staff_who_may_not_invite_are_refused_on_store(): void
    {
        $this->actingAs($this->manager())->post($this->panelUrl('/admin/onboarding/team'), $this->invitation('x@example.test'))->assertForbidden();
    }

    private function stepIsComplete(string $key): bool
    {
        foreach ($this->app->make(OnboardingProgress::class)->report()->steps as $progress) {
            if ($progress->step->key() === $key) {
                return $progress->complete;
            }
        }

        $this->fail("No step [{$key}].");
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Analyst->value);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function invitation(string $email): array
    {
        return [
            'name'    => 'Invited',
            'email'   => $email,
            'phone'   => '',
            'role_id' => (string) Role::query()->where('name', RoleEnum::Analyst->value)->value('id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function telegramPayload(array $overrides = []): array
    {
        return [
            'type'         => 'telegram',
            'token'        => self::TOKEN,
            'secret_token' => self::SECRET,
            'is_active'    => true,
            'config'       => ['allowed_updates' => ['message'], 'max_connections' => 25],
            ...$overrides,
        ];
    }
}
