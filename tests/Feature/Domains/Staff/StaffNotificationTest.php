<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Staff\Mail\StaffNotificationMail;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffMessageNotification;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

final class StaffNotificationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_resolver_returns_staff_assigned_to_assistant(): void
    {
        $assistant = Assistant::factory()->create();
        $assigned  = User::factory()->create();
        $other     = User::factory()->create();
        $assigned->assistants()->attach($assistant->id);

        $recipients = $this->resolver()->resolve(['target' => 'assistant'], $assistant->id);

        $this->assertEqualsCanonicalizing([$assigned->id], $recipients->pluck('id')->all());
        $this->assertNotContains($other->id, $recipients->pluck('id')->all());
    }

    public function test_resolver_filters_inactive_users(): void
    {
        $assistant = Assistant::factory()->create();
        $inactive  = User::factory()->create(['is_active' => false]);
        $disabled  = User::factory()->create(['status' => UserStatus::Suspended]);
        $inactive->assistants()->attach($assistant->id);
        $disabled->assistants()->attach($assistant->id);

        $recipients = $this->resolver()->resolve(['target' => 'assistant'], $assistant->id);

        $this->assertTrue($recipients->isEmpty());
    }

    public function test_resolver_selects_by_role(): void
    {
        $role     = Role::query()->create(['name' => 'manager', 'guard_name' => 'web', 'priority' => 50]);
        $withRole = User::factory()->create();
        User::factory()->create();
        $withRole->assignRole($role);

        $recipients = $this->resolver()->resolve(['target' => 'role', 'role' => 'manager'], null);

        $this->assertEqualsCanonicalizing([$withRole->id], $recipients->pluck('id')->all());
    }

    public function test_resolver_selects_explicit_users(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        User::factory()->create();

        $recipients = $this->resolver()->resolve(['target' => 'users', 'user_ids' => [$a->id, $b->id]], null);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $recipients->pluck('id')->all());
    }

    public function test_job_delivers_in_app_notification(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assistants()->attach($assistant->id);
        $session = $this->createSession($assistant->id);

        $this->runJob($session, ['target' => 'assistant'], [StaffNotifyChannel::InApp->value]);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
    }

    /**
     * The row keeps the shape Filament's database notifications wrote, so older and newer rows read the same way. Key
     * order is not compared: it carries no meaning in the stored JSON.
     */
    public function test_an_in_app_notification_is_stored_in_the_shape_filament_wrote(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assistants()->attach($assistant->id);

        $this->runJob($this->createSession($assistant->id), ['target' => 'assistant'], [StaffNotifyChannel::InApp->value]);

        $row  = DB::table('notifications')->where('notifiable_id', $user->id)->sole();
        $data = json_decode((string) $row->data, true, flags: JSON_THROW_ON_ERROR);

        $expected = [
            'actions'   => [],
            'body'      => null,
            'color'     => null,
            'duration'  => 'persistent',
            'icon'      => 'heroicon-o-information-circle',
            'iconColor' => 'info',
            'status'    => 'info',
            'title'     => 'Operator needed',
            'view'      => null,
            'viewData'  => [],
            'format'    => 'filament',
        ];

        $this->assertEquals($this->canonical($expected), $this->canonical($data));
        $this->assertNull($row->read_at);
    }

    /**
     * Written in the notify job's tenant switch: a queued notification would restore the user outside the tenant.
     */
    public function test_the_in_app_notification_is_never_queued(): void
    {
        $this->assertNotInstanceOf(ShouldQueue::class, new StaffMessageNotification(new StaffMessage('Operator needed')));
    }

    public function test_job_delivers_email(): void
    {
        Mail::fake();
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assistants()->attach($assistant->id);
        $session = $this->createSession($assistant->id);

        $this->runJob($session, ['target' => 'assistant'], [StaffNotifyChannel::Email->value]);

        Mail::assertSent(StaffNotificationMail::class, fn (StaffNotificationMail $m): bool => $m->hasTo($user->email));
    }

    /**
     * A message with a link keeps Filament's shape and puts the link in its one action, which the bell opens.
     */
    public function test_an_in_app_message_with_a_link_stores_it_as_its_action(): void
    {
        $user = User::factory()->create();

        $this->app->make(StaffNotifierRegistry::class)->get(StaffNotifyChannel::InApp->value)
            ?->send($user, new StaffMessage('Operator requested', '/assistant/a1/conversations/c1', 'Open'));

        $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $user->id)->sole()->data, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([['name' => 'open', 'label' => 'Open', 'url' => '/assistant/a1/conversations/c1']], $data['actions']);
        $this->assertSame('Operator requested', $data['title']);
        $this->assertSame('filament', $data['format']);
    }

    /**
     * A mail is read outside the console: a path becomes an address on the tenant's host; an address stays as it is.
     */
    public function test_an_email_with_a_link_carries_an_absolute_address(): void
    {
        Mail::fake();
        $user   = User::factory()->create();
        $mailer = $this->app->make(StaffNotifierRegistry::class)->get(StaffNotifyChannel::Email->value);

        $mailer?->send($user, new StaffMessage('Operator requested', '/assistant/a1/conversations/c1'));
        $mailer?->send($user, new StaffMessage('Elsewhere', 'https://panel.example.test/x'));
        $mailer?->send($user, new StaffMessage('No link'));

        Mail::assertSent(StaffNotificationMail::class, fn (StaffNotificationMail $mail): bool => 'Operator requested' === $mail->messageBody
            && null !== $mail->actionUrl
            && str_starts_with($mail->actionUrl, 'http')
            && str_ends_with($mail->actionUrl, '/assistant/a1/conversations/c1'));
        Mail::assertSent(StaffNotificationMail::class, fn (StaffNotificationMail $mail): bool => 'Elsewhere' === $mail->messageBody
            && 'https://panel.example.test/x' === $mail->actionUrl);
        Mail::assertSent(StaffNotificationMail::class, fn (StaffNotificationMail $mail): bool => 'No link' === $mail->messageBody
            && null === $mail->actionUrl);
    }

    public function test_job_is_idempotent_on_retry(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assistants()->attach($assistant->id);
        $session = $this->createSession($assistant->id);

        $this->runJob($session, ['target' => 'assistant'], [StaffNotifyChannel::InApp->value]);
        // Same (session, node) — the guard must suppress a second delivery.
        $this->runJob($session, ['target' => 'assistant'], [StaffNotifyChannel::InApp->value]);

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function canonical(array $data): array
    {
        ksort($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  list<string>          $channels
     */
    private function runJob(string $sessionId, array $target, array $channels): void
    {
        $job = new SendStaffNotificationJob(
            tenantId: '00000000-0000-0000-0000-000000000001',
            sessionId: $sessionId,
            nodeId: 'node-notify',
            targetConfig: $target,
            channels: $channels,
            message: 'Operator needed',
        );

        app()->call([$job, 'handle']);
    }

    private function createSession(string $assistantId): string
    {
        $tenantId = '00000000-0000-0000-0000-000000000001';
        $contact  = \App\Domains\Contact\Models\Contact::factory()->forTenant($tenantId)->create();
        $flow     = \App\Domains\Flow\Models\FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) \Illuminate\Support\Str::uuid(),
            'version'   => 1,
            'name'      => 'Notify Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = \App\Domains\Flow\Models\FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistantId,
            'contact_id'         => $contact->id,
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-notify',
            'state'              => [],
            'status'             => \App\Domains\Flow\Enums\FlowSessionStatus::Active,
            'version'            => 0,
        ]);

        return $session->id;
    }

    private function resolver(): StaffRecipientResolver
    {
        return app(StaffRecipientResolver::class);
    }
}
