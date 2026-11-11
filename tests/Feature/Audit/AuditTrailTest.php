<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Audit\Exceptions\AuditEntryNotRecordedException;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Services\RoleWriterService;
use App\Domains\Staff\Support\SupportAccessSession;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Tests\Feature\FeatureTestCase;
use Tests\Support\ComparesJsonColumns;

/**
 * The audit trail itself: what an entry stores, who it names as the actor, that it lives and dies with the transaction
 * of the change it records, and that a secret never reaches it.
 */
final class AuditTrailTest extends FeatureTestCase
{
    use ComparesJsonColumns;

    public function test_an_entry_names_the_action_the_area_the_subject_and_the_staff_user(): void
    {
        $user      = User::factory()->create(['name' => 'Anna']);
        $assistant = Assistant::factory()->create(['name' => 'Helper']);
        $this->actingAs($user);

        $this->trail()->record(new AuditRecord(
            action: AuditAction::AssistantUpdated,
            subject: $assistant,
            subjectLabel: 'Helper',
            assistantId: (string) $assistant->getKey(),
            changes: AuditChanges::between(['name' => 'Old', 'is_active' => true], ['name' => 'Helper', 'is_active' => true], ['name', 'is_active'], ['description']),
            properties: ['reason' => 'test'],
        ));

        $entry = ActivityEntry::query()->sole();

        $this->assertTrue(Str::isUuid($entry->id));
        $this->assertSame('assistants', $entry->log_name);
        $this->assertSame('assistant.updated', $entry->description);
        $this->assertSame('updated', $entry->event);
        $this->assertSame(AuditActorKind::Staff, $entry->actor_kind);
        $this->assertSame('Anna', $entry->actor_label);
        $this->assertSame((string) $user->getKey(), $entry->causer_id);
        $this->assertSame(User::class, $entry->causer_type);
        $this->assertSame((string) $assistant->getKey(), $entry->subject_id);
        $this->assertSame('Helper', $entry->subject_label);
        $this->assertSame((string) $assistant->getKey(), $entry->assistant_id);
        $this->assertSameJson(['attributes' => ['name' => 'Helper'], 'old' => ['name' => 'Old']], $entry->attribute_changes?->toArray());
        $this->assertSameJson(['reason' => 'test'], $entry->properties?->toArray());
        $this->assertNull($entry->getAttribute('updated_at'));
    }

    public function test_a_value_read_back_with_its_object_keys_re_ordered_is_not_a_change(): void
    {
        // A jsonb column (Postgres) hands an object back with its keys re-ordered; the order of a list still counts.
        $stored = ['commands' => [['type' => 'send_message', 'command' => 'help', 'text' => ['en' => 'Hi']]], 'tags' => ['a', 'b']];
        $saved  = ['commands' => [['command' => 'help', 'text' => ['en' => 'Hi'], 'type' => 'send_message']], 'tags' => ['a', 'b']];

        $this->assertTrue(AuditChanges::between($stored, $saved, ['tags'], ['commands'])->isEmpty());
        $this->assertSame(
            ['attributes' => ['tags' => ['b', 'a']], 'old' => ['tags' => ['a', 'b']]],
            AuditChanges::between($stored, ['tags' => ['b', 'a']] + $saved, ['tags'], ['commands'])->toArray(),
        );
    }

    public function test_without_a_signed_in_user_the_actor_is_the_system(): void
    {
        $this->trail()->record(new AuditRecord(action: AuditAction::SettingsUpdated));

        $entry = ActivityEntry::query()->sole();

        $this->assertSame(AuditActorKind::System, $entry->actor_kind);
        $this->assertNull($entry->causer_id);
        $this->assertNull($entry->actor_label);
    }

    public function test_the_actor_of_one_job_does_not_reach_the_next(): void
    {
        $this->actingAs(User::factory()->create());
        $this->trail()->record(new AuditRecord(action: AuditAction::SettingsUpdated));

        // What a worker does between jobs: scoped bindings are rebuilt, and the next job has nobody signed in.
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();

        $this->trail()->record(new AuditRecord(action: AuditAction::SettingsUpdated));

        $this->assertSame(
            [AuditActorKind::Staff, AuditActorKind::System],
            ActivityEntry::query()->orderBy('id')->get()->pluck('actor_kind')->all(),
        );
    }

    public function test_platform_support_is_recorded_with_the_operator_and_the_support_entry(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        $this->seed(TenantAclSeeder::class);
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();
        $this->actingAs($support);

        $request = Request::create('/');
        $request->setLaravelSession($session = $this->app['session.store']);
        $session->put(SupportAccessSession::KEY, [
            'entry_id'       => 'entry-1',
            'operator_name'  => 'Olga',
            'operator_email' => 'olga@example.com',
            'expires_at'     => now()->addHour()->toIso8601String(),
        ]);
        $this->app->instance('request', $request);

        $this->trail()->record(new AuditRecord(action: AuditAction::SettingsUpdated));

        $entry = ActivityEntry::query()->sole();

        $this->assertSame(AuditActorKind::Support, $entry->actor_kind);
        $this->assertSame('Platform support (Olga)', $entry->actor_label);
        $this->assertSame((string) $support->getKey(), $entry->causer_id);
        $this->assertSameJson(['support_entry_id' => 'entry-1', 'operator_name' => 'Olga'], $entry->properties?->toArray());
        $this->assertStringNotContainsString('olga@example.com', (string) json_encode($entry->getAttributes()));
    }

    public function test_a_rolled_back_change_leaves_no_entry(): void
    {
        try {
            DB::transaction(function (): void {
                $this->trail()->record(new AuditRecord(action: AuditAction::SettingsUpdated));

                throw new RuntimeException('the change failed after it was recorded');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_an_entry_that_cannot_be_written_fails_the_change(): void
    {
        $failing = new class () extends LogActivityAction {
            protected function save(Model $activity): void
            {
                throw new RuntimeException('insert refused');
            }
        };
        config(['activitylog.actions.log_activity' => $failing::class]);

        try {
            DB::transaction(function (): void {
                Assistant::factory()->create(['name' => 'Unrecorded']);
                $this->trail()->record(new AuditRecord(action: AuditAction::AssistantCreated));
            });
            $this->fail('The change passed although its entry was not written.');
        } catch (RuntimeException $exception) {
            $this->assertSame('insert refused', $exception->getMessage());
        }

        $this->assertFalse(Assistant::query()->where('name', 'Unrecorded')->exists());
    }

    public function test_a_disabled_logger_fails_the_change_instead_of_passing_it_unrecorded(): void
    {
        $this->app->make(ActivityLogStatus::class)->disable();

        $this->expectException(AuditEntryNotRecordedException::class);

        $this->trail()->record(new AuditRecord(action: AuditAction::SettingsUpdated));
    }

    public function test_a_secret_key_never_reaches_an_entry_even_when_a_caller_passes_it(): void
    {
        $this->trail()->record(new AuditRecord(
            action: AuditAction::ChannelUpdated,
            changes: AuditChanges::between(
                ['token' => 'old-SECRET', 'is_active' => true],
                ['token' => 'new-SECRET', 'is_active' => false],
                ['token', 'is_active'],
            ),
            properties: ['secret_token' => 'SECRET', 'webhook_public_hash' => 'SECRET', 'kept' => 'yes'],
        ));

        $raw = (string) json_encode(ActivityEntry::query()->sole()->getAttributes());

        $this->assertStringNotContainsString('SECRET', $raw);
        $this->assertStringContainsString('kept', $raw);
        $this->assertStringContainsString('is_active', $raw);
    }

    public function test_a_service_that_records_is_never_kept_for_the_next_job(): void
    {
        // A singleton would hold the scoped trail, and with it the first job's actor and log status.
        $this->assertNotSame(
            $this->app->make(RoleWriterService::class),
            $this->app->make(RoleWriterService::class),
        );
    }

    private function trail(): AuditTrailInterface
    {
        return $this->app->make(AuditTrailInterface::class);
    }
}
