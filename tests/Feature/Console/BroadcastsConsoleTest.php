<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastRecipientResolver;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Broadcasts on the Inertia console. A broadcast reaches real people, so most of this file is about what must not
 * happen: a second run, a send of something other than what was confirmed, a change to a run that has started, an
 * audience that is gone or is somebody else's, a send nobody authorized.
 */
final class BroadcastsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();
    }

    // ---- list ----------------------------------------------------------------------------------------------------

    public function test_the_list_shows_only_the_broadcasts_of_this_tenant_and_assistant(): void
    {
        $mine = $this->broadcast('Mine');
        $this->broadcast('Other assistant', assistant: Assistant::factory()->create());
        $this->broadcast('Other tenant', ['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Broadcasts/Index')
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $mine->getKey())
                ->where('table.state', ['search' => '', 'sort' => '-created_at', 'perPage' => 25, 'filters' => []])
                ->where('statuses', ['draft', 'running', 'completed', 'failed', 'cancelled'])
                ->where('can', ['create' => true, 'update' => true, 'delete' => true, 'send' => true, 'cancel' => true])
                ->where('urls', [
                    'index'  => "/assistant/{$this->assistant->getKey()}/broadcasts",
                    'create' => "/assistant/{$this->assistant->getKey()}/broadcasts/create",
                    'reach'  => "/assistant/{$this->assistant->getKey()}/broadcasts/reach",
                ])
                ->etc());
    }

    public function test_each_row_offers_only_the_steps_its_status_allows(): void
    {
        $draft     = $this->broadcast('Draft');
        $running   = $this->broadcast('Running', ['status' => BroadcastStatus::Running->value, 'total_recipients' => 10, 'sent_count' => 4, 'failed_count' => 1, 'skipped_count' => 2]);
        $completed = $this->broadcast('Completed', ['status' => BroadcastStatus::Completed->value]);
        $base      = "/assistant/{$this->assistant->getKey()}/broadcasts";

        $rows = [];

        $this->actingAs($this->admin())
            ->get($this->listUrl('?sort=name'))
            ->assertInertia(function (AssertableInertia $page) use (&$rows): void {
                $rows = collect($page->toArray()['props']['table']['rows'])->keyBy('name')->all();
            });

        $this->assertSame(64, mb_strlen((string) $rows['Draft']['revision']));
        $this->assertSame("{$base}/{$draft->getKey()}/edit", $rows['Draft']['editUrl']);
        $this->assertSame("{$base}/{$draft->getKey()}/send", $rows['Draft']['sendUrl']);
        $this->assertSame("{$base}/{$draft->getKey()}", $rows['Draft']['deleteUrl']);
        $this->assertNull($rows['Draft']['cancelUrl']);

        $this->assertNull($rows['Running']['revision']);
        $this->assertNull($rows['Running']['editUrl']);
        $this->assertNull($rows['Running']['sendUrl']);
        $this->assertNull($rows['Running']['deleteUrl'], 'A running broadcast cannot be deleted.');
        $this->assertSame("{$base}/{$running->getKey()}/cancel", $rows['Running']['cancelUrl']);
        $this->assertSame(['running', 4, 10, 1, 2], [$rows['Running']['status'], $rows['Running']['sent'], $rows['Running']['total'], $rows['Running']['failed'], $rows['Running']['skipped']]);

        $this->assertNull($rows['Completed']['revision']);
        $this->assertNull($rows['Completed']['sendUrl'], 'A finished broadcast cannot be sent again.');
        $this->assertNull($rows['Completed']['editUrl']);
        $this->assertNull($rows['Completed']['cancelUrl']);
        $this->assertSame("{$base}/{$completed->getKey()}", $rows['Completed']['deleteUrl']);
    }

    public function test_the_list_searches_filters_by_status_and_shows_the_newest_first(): void
    {
        $this->broadcast('Old promo', ['created_at' => now()->subDays(2)]);
        $this->broadcast('New promo', ['created_at' => now()]);
        $this->broadcast('Winter sale', ['status' => BroadcastStatus::Completed->value, 'created_at' => now()->subDay()]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.name', 'New promo')->where('table.rows.2.name', 'Old promo')->etc());

        $this->get($this->listUrl('?search=PROMO'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 2)->etc());

        $this->get($this->listUrl('?filter[status]=completed'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', 'Winter sale')
                ->where('table.state.filters', ['status' => 'completed'])
                ->etc());

        $this->get($this->listUrl('?filter[status]=bogus'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 3)->where('table.state.filters', [])->etc());
    }

    public function test_the_revision_does_not_depend_on_the_order_of_keys_or_tags(): void
    {
        $this->broadcast('A', ['message' => ['en' => 'Hello', 'ru' => 'Привет'], 'target_type' => 'tags', 'target_tags' => ['b', 'a']]);
        $this->broadcast('A', ['message' => ['ru' => 'Привет', 'en' => 'Hello'], 'target_type' => 'tags', 'target_tags' => ['a', 'b']]);

        $revisions = [];

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertInertia(function (AssertableInertia $page) use (&$revisions): void {
                $revisions = array_column($page->toArray()['props']['table']['rows'], 'revision');
            });

        $this->assertCount(2, $revisions);
        $this->assertSame($revisions[0], $revisions[1]);
    }

    // ---- authorization and isolation -----------------------------------------------------------------------------

    public function test_a_user_without_the_broadcast_permission_is_refused_everywhere(): void
    {
        Bus::fake();
        $draft   = $this->broadcast('Hidden');
        $running = $this->broadcast('Busy', ['status' => BroadcastStatus::Running->value]);
        $payload = $this->payload();

        $this->actingAs($this->userWith(Permission::ManageFlowGroups));

        $this->get($this->listUrl())->assertForbidden();
        $this->get($this->listUrl('/create'))->assertForbidden();
        $this->get($this->listUrl('/reach?target_type=all'))->assertForbidden();
        $this->post($this->listUrl(), $payload)->assertForbidden();
        $this->get($this->listUrl("/{$draft->getKey()}/edit"))->assertForbidden();
        $this->put($this->listUrl("/{$draft->getKey()}"), $payload)->assertForbidden();
        $this->post($this->listUrl("/{$draft->getKey()}/send"), ['revision' => str_repeat('a', 64)])->assertForbidden();
        $this->post($this->listUrl("/{$running->getKey()}/cancel"))->assertForbidden();
        $this->delete($this->listUrl("/{$draft->getKey()}"))->assertForbidden();

        Bus::assertNotDispatched(RunBroadcastJob::class);
        $this->assertSame(BroadcastStatus::Draft, $draft->fresh()->status);
        $this->assertSame(BroadcastStatus::Running, $running->fresh()->status);
        $this->assertSame(2, Broadcast::query()->count());
    }

    public function test_a_manager_cannot_reach_the_broadcasts_of_an_assistant_they_are_not_assigned_to(): void
    {
        $unassigned = Assistant::factory()->create();
        $broadcast  = $this->broadcast('Theirs', assistant: $unassigned);

        $this->actingAs($this->userWith(Permission::ManageBroadcast));

        $this->get($this->panelUrl("/assistant/{$unassigned->getKey()}/broadcasts"))->assertNotFound();
        $this->post($this->panelUrl("/assistant/{$unassigned->getKey()}/broadcasts/{$broadcast->getKey()}/send"), ['revision' => str_repeat('a', 64)])->assertNotFound();
        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
    }

    public function test_a_broadcast_of_another_assistant_or_tenant_or_a_malformed_id_is_not_found(): void
    {
        Bus::fake();
        $otherAssistant = $this->broadcast('Elsewhere', assistant: Assistant::factory()->create());
        $foreign        = $this->broadcast('Foreign', ['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->actingAs($this->admin());

        foreach ([(string) $otherAssistant->getKey(), (string) $foreign->getKey(), 'not-a-uuid', (string) Str::uuid()] as $id) {
            $this->get($this->listUrl("/{$id}/edit"))->assertNotFound();
            $this->put($this->listUrl("/{$id}"), $this->payload())->assertNotFound();
            $this->post($this->listUrl("/{$id}/send"), ['revision' => str_repeat('a', 64)])->assertNotFound();
            $this->post($this->listUrl("/{$id}/cancel"))->assertNotFound();
            $this->delete($this->listUrl("/{$id}"))->assertNotFound();
        }

        Bus::assertNotDispatched(RunBroadcastJob::class);
        $this->assertSame(2, Broadcast::query()->count());
        $this->assertSame('Elsewhere', $otherAssistant->fresh()->name);
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->listUrl())->assertRedirect(route('filament.admin.auth.login'));
    }

    // ---- the form ------------------------------------------------------------------------------------------------

    public function test_the_create_screen_opens_with_the_tabs_in_order_and_the_options(): void
    {
        TenantSettings::fake(['content_base_language' => 'ru', 'available_languages' => ['uk', 'ru']]);
        $this->segment('Beta');
        $this->segment('Alpha');
        $this->segment('Foreign', self::OTHER_TENANT_ID);
        $this->tag('vip');
        $this->tag('new');

        $this->actingAs($this->admin())
            ->get($this->listUrl('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Broadcasts/Create')
                ->where('form.targetType', 'all')
                ->where('form.message', [['locale' => 'ru', 'text' => ''], ['locale' => 'uk', 'text' => ''], ['locale' => 'en', 'text' => '']])
                ->where('languages', [['code' => 'ru', 'isBase' => true], ['code' => 'uk', 'isBase' => false], ['code' => 'en', 'isBase' => false]])
                ->where('options.tags', ['new', 'vip'])
                ->where('options.segments.0.label', 'Alpha')
                ->where('options.segments.1.label', 'Beta')
                ->has('options.segments', 2)
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/broadcasts")
                ->where('urls.reach', "/assistant/{$this->assistant->getKey()}/broadcasts/reach")
                ->etc());
    }

    public function test_the_edit_screen_shows_the_saved_state_and_keeps_a_language_the_message_already_has(): void
    {
        $segment   = $this->segment('VIPs');
        $broadcast = $this->broadcast('Promo', [
            'message'           => ['de' => 'Hallo', 'en' => 'Hello'],
            'target_type'       => 'segment',
            'target_segment_id' => $segment->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$broadcast->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Broadcasts/Edit')
                ->where('form.name', 'Promo')
                ->where('form.message', [['locale' => 'en', 'text' => 'Hello'], ['locale' => 'de', 'text' => 'Hallo']])
                ->where('form.targetType', 'segment')
                ->where('form.targetSegmentId', (string) $segment->getKey())
                ->where('form.segmentMissing', false)
                ->where('urls.submit', "/assistant/{$this->assistant->getKey()}/broadcasts/{$broadcast->getKey()}")
                ->etc());
    }

    public function test_a_deleted_segment_shows_as_missing_and_a_vanished_tag_is_not_offered(): void
    {
        $this->tag('kept');
        $gone  = $this->broadcast('Gone segment', ['target_type' => 'segment', 'target_segment_id' => (string) Str::uuid()]);
        $stale = $this->broadcast('Stale tag', ['target_type' => 'tags', 'target_tags' => ['kept', 'vanished']]);

        $this->actingAs($this->admin());

        $this->get($this->listUrl("/{$gone->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('form.segmentMissing', true)->where('form.targetSegmentId', null)->etc());

        $this->get($this->listUrl("/{$stale->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('form.targetTags', ['kept'])->etc());
    }

    public function test_a_broadcast_that_is_no_longer_a_draft_does_not_open_in_the_form(): void
    {
        $running = $this->broadcast('Running', ['status' => BroadcastStatus::Running->value]);

        $this->actingAs($this->admin())
            ->get($this->listUrl("/{$running->getKey()}/edit"))
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('error');
    }

    // ---- create and update ---------------------------------------------------------------------------------------

    public function test_a_draft_is_created_for_the_assistant_and_the_user(): void
    {
        TenantSettings::fake(['content_base_language' => 'en', 'available_languages' => ['en', 'ru', 'uk']]);
        $user = $this->admin();

        $this->actingAs($user)
            ->post($this->listUrl(), $this->payload(['message' => ['en' => 'Hello', 'ru' => '  ', 'uk' => null]]))
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $broadcast = Broadcast::query()->firstOrFail();

        $this->assertSame(self::TENANT_ID, $broadcast->tenant_id);
        $this->assertSame((string) $this->assistant->getKey(), $broadcast->assistant_id);
        $this->assertSame((string) $user->getKey(), $broadcast->created_by);
        $this->assertSame(BroadcastStatus::Draft, $broadcast->status);
        $this->assertEquals(['en' => 'Hello'], $broadcast->message, 'Blank languages are dropped.');
        $this->assertSame('all', $broadcast->target_type->value);
    }

    public function test_the_base_language_needs_text(): void
    {
        TenantSettings::fake(['content_base_language' => 'ru', 'available_languages' => ['ru', 'en']]);
        $this->actingAs($this->admin());

        // The base language is empty, blank, or missing while another language is filled.
        $this->post($this->listUrl(), $this->payload(['message' => ['en' => 'Hello', 'ru' => '']]))->assertSessionHasErrors('message.ru');
        $this->post($this->listUrl(), $this->payload(['message' => ['en' => 'Hello', 'ru' => "  \n "]]))->assertSessionHasErrors('message.ru');
        $this->post($this->listUrl(), $this->payload(['message' => ['en' => 'Hello']]))->assertSessionHasErrors('message.ru');

        $this->assertSame(0, Broadcast::query()->count());

        $this->post($this->listUrl(), $this->payload(['message' => ['ru' => 'Привет']]))->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_the_base_language_error_names_the_language(): void
    {
        TenantSettings::fake(['content_base_language' => 'uk', 'available_languages' => ['uk', 'en']]);

        $this->actingAs($this->admin())
            ->post($this->listUrl(), $this->payload(['message' => ['en' => 'Hello']]))
            ->assertSessionHasErrors(['message.uk' => trans('console.broadcasts.errors.base_language', ['language' => 'UK'])]);

    }

    public function test_only_the_languages_of_the_form_and_texts_up_to_the_limit_are_accepted(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), $this->payload(['message' => ['en' => 'Hello', 'de' => 'Hallo']]))->assertSessionHasErrors('message.de');
        $this->post($this->listUrl(), $this->payload(['message' => ['en' => str_repeat('a', 4097)]]))->assertSessionHasErrors('message.en');
        $this->post($this->listUrl(), $this->payload(['message' => []]))->assertSessionHasErrors('message');
        $this->post($this->listUrl(), $this->payload(['name' => '']))->assertSessionHasErrors('name');
        $this->post($this->listUrl(), $this->payload(['name' => str_repeat('a', 256)]))->assertSessionHasErrors('name');
        $this->post($this->listUrl(), $this->payload(['target_type' => 'everyone']))->assertSessionHasErrors('target_type');

        $this->assertSame(0, Broadcast::query()->count());

        $this->post($this->listUrl(), $this->payload(['message' => ['en' => str_repeat('a', 4096)]]))->assertSessionDoesntHaveErrors();
        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_a_tag_audience_needs_tags_that_exist(): void
    {
        $this->tag('vip');
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), $this->payload(['target_type' => 'tags']))->assertSessionHasErrors('target_tags');
        $this->post($this->listUrl(), $this->payload(['target_type' => 'tags', 'target_tags' => []]))->assertSessionHasErrors('target_tags');
        $this->post($this->listUrl(), $this->payload(['target_type' => 'tags', 'target_tags' => ['nobody']]))->assertSessionHasErrors('target_tags.0');
        $this->assertSame(0, Broadcast::query()->count());

        $this->post($this->listUrl(), $this->payload(['target_type' => 'tags', 'target_tags' => ['vip']]))->assertSessionDoesntHaveErrors();

        $this->assertSame(['vip'], Broadcast::query()->firstOrFail()->target_tags);
    }

    public function test_a_segment_audience_needs_a_segment_of_this_tenant(): void
    {
        $mine    = $this->segment('Mine');
        $foreign = $this->segment('Foreign', self::OTHER_TENANT_ID);
        $this->actingAs($this->admin());

        $this->post($this->listUrl(), $this->payload(['target_type' => 'segment']))->assertSessionHasErrors('target_segment_id');
        $this->post($this->listUrl(), $this->payload(['target_type' => 'segment', 'target_segment_id' => 'nope']))->assertSessionHasErrors('target_segment_id');
        $this->post($this->listUrl(), $this->payload(['target_type' => 'segment', 'target_segment_id' => (string) Str::uuid()]))->assertSessionHasErrors('target_segment_id');
        $this->post($this->listUrl(), $this->payload(['target_type' => 'segment', 'target_segment_id' => (string) $foreign->getKey()]))->assertSessionHasErrors('target_segment_id');
        $this->assertSame(0, Broadcast::query()->count());

        $this->post($this->listUrl(), $this->payload(['target_type' => 'segment', 'target_segment_id' => (string) $mine->getKey()]))->assertSessionDoesntHaveErrors();

        $this->assertSame((string) $mine->getKey(), Broadcast::query()->firstOrFail()->target_segment_id);
    }

    public function test_fields_the_chosen_audience_does_not_use_are_not_kept(): void
    {
        $this->tag('vip');
        $segment   = $this->segment('VIPs');
        $broadcast = $this->broadcast('Promo', ['target_type' => 'tags', 'target_tags' => ['vip']]);

        $this->actingAs($this->admin());

        $this->put($this->listUrl("/{$broadcast->getKey()}"), $this->payload(['target_type' => 'segment', 'target_segment_id' => (string) $segment->getKey(), 'target_tags' => ['vip']]))
            ->assertSessionDoesntHaveErrors();
        $broadcast->refresh();
        $this->assertNull($broadcast->target_tags);
        $this->assertSame((string) $segment->getKey(), $broadcast->target_segment_id);

        $this->put($this->listUrl("/{$broadcast->getKey()}"), $this->payload(['target_type' => 'all', 'target_segment_id' => (string) $segment->getKey(), 'target_tags' => ['vip']]))
            ->assertSessionDoesntHaveErrors();
        $broadcast->refresh();
        $this->assertNull($broadcast->target_tags);
        $this->assertNull($broadcast->target_segment_id);
    }

    public function test_a_draft_is_changed(): void
    {
        $broadcast = $this->broadcast('Old');

        $this->actingAs($this->admin())
            ->put($this->listUrl("/{$broadcast->getKey()}"), $this->payload(['name' => 'New', 'message' => ['en' => 'Changed']]))
            ->assertRedirect($this->listUrl())
            ->assertInertiaFlash('success');

        $broadcast->refresh();
        $this->assertSame('New', $broadcast->name);
        $this->assertEquals(['en' => 'Changed'], $broadcast->message);
        $this->assertSame(BroadcastStatus::Draft, $broadcast->status);
    }

    public function test_a_broadcast_that_is_not_a_draft_is_not_changed(): void
    {
        $this->actingAs($this->admin());

        foreach ([BroadcastStatus::Running, BroadcastStatus::Completed, BroadcastStatus::Cancelled, BroadcastStatus::Failed] as $status) {
            $broadcast = $this->broadcast('Started', ['status' => $status->value, 'message' => ['en' => 'Original']]);

            $this->put($this->listUrl("/{$broadcast->getKey()}"), $this->payload(['name' => 'Hijacked', 'message' => ['en' => 'Changed']]))
                ->assertRedirect($this->listUrl())
                ->assertInertiaFlash('error');

            $broadcast->refresh();
            $this->assertSame('Started', $broadcast->name);
            $this->assertEquals(['en' => 'Original'], $broadcast->message);
            $this->assertSame($status, $broadcast->status);
        }
    }

    // ---- send ----------------------------------------------------------------------------------------------------

    public function test_a_draft_is_sent_once_on_the_revision_that_was_confirmed(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Promo');

        $this->actingAs($this->admin())
            ->from($this->listUrl('?sort=name'))
            ->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($broadcast)])
            ->assertRedirect($this->listUrl('?sort=name'))
            ->assertInertiaFlash('success');

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Running, $broadcast->status);
        $this->assertNotNull($broadcast->started_at);
        Bus::assertDispatchedTimes(RunBroadcastJob::class, 1);
        Bus::assertDispatched(RunBroadcastJob::class, static fn (RunBroadcastJob $job): bool => $job->broadcastId === (string) $broadcast->getKey() && self::TENANT_ID === $job->tenantId);
    }

    public function test_sending_twice_starts_the_run_once(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Promo');
        $revision  = $this->revisionOf($broadcast);

        $this->actingAs($this->admin());

        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $revision])->assertInertiaFlash('success');
        // The double click, the second tab, the repeated POST, Back and submit again.
        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $revision])->assertInertiaFlash('error');
        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $revision])->assertInertiaFlash('error');

        Bus::assertDispatchedTimes(RunBroadcastJob::class, 1);
        $this->assertSame(BroadcastStatus::Running, $broadcast->fresh()->status);
    }

    public function test_a_finished_or_cancelled_broadcast_is_never_started_again(): void
    {
        Bus::fake();
        $this->actingAs($this->admin());

        foreach ([BroadcastStatus::Completed, BroadcastStatus::Cancelled, BroadcastStatus::Failed, BroadcastStatus::Running] as $status) {
            $broadcast = $this->broadcast('Done', ['status' => $status->value]);

            // The revision of an equal draft is what a client replaying an old confirmation would hold.
            $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($this->broadcast('Done'))])->assertInertiaFlash('error');

            $this->assertSame($status, $broadcast->fresh()->status);
        }

        Bus::assertNotDispatched(RunBroadcastJob::class);
    }

    public function test_a_send_without_a_revision_is_refused(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Promo');

        $this->actingAs($this->admin());

        $this->post($this->listUrl("/{$broadcast->getKey()}/send"))->assertSessionHasErrors('revision');
        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => ''])->assertSessionHasErrors('revision');
        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => 'short'])->assertSessionHasErrors('revision');

        Bus::assertNotDispatched(RunBroadcastJob::class);
        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
    }

    public function test_a_draft_edited_after_it_was_shown_is_not_sent_on_the_old_confirmation(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Promo', ['message' => ['en' => 'Original text']]);
        $shown     = $this->revisionOf($broadcast);

        $this->actingAs($this->admin());

        // Another tab changes the text and the audience after this one opened the dialog.
        $this->put($this->listUrl("/{$broadcast->getKey()}"), $this->payload(['message' => ['en' => 'Different text']]))->assertSessionDoesntHaveErrors();

        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $shown])->assertInertiaFlash('error');

        Bus::assertNotDispatched(RunBroadcastJob::class);
        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Draft, $broadcast->status);
        $this->assertNull($broadcast->started_at);

        // Looking again gives the new revision, and that one goes out.
        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($broadcast)])->assertInertiaFlash('success');
        Bus::assertDispatchedTimes(RunBroadcastJob::class, 1);
    }

    public function test_a_change_of_audience_alone_changes_the_revision(): void
    {
        Bus::fake();
        $this->tag('vip');
        $broadcast = $this->broadcast('Promo', ['target_type' => 'tags', 'target_tags' => ['vip']]);
        $shown     = $this->revisionOf($broadcast);

        $broadcast->update(['target_type' => 'all', 'target_tags' => null]);

        $this->actingAs($this->admin())
            ->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $shown])
            ->assertInertiaFlash('error');

        Bus::assertNotDispatched(RunBroadcastJob::class);
        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
    }

    public function test_a_started_run_keeps_the_message_it_started_with(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Promo', ['message' => ['en' => 'Original text']]);

        $this->actingAs($this->admin());

        $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($broadcast)])->assertInertiaFlash('success');

        $this->put($this->listUrl("/{$broadcast->getKey()}"), $this->payload(['name' => 'Renamed', 'message' => ['en' => 'Changed after the start']]))
            ->assertInertiaFlash('error');

        $broadcast->refresh();
        $this->assertEquals(['en' => 'Original text'], $broadcast->message);
        $this->assertSame('Promo', $broadcast->name);
    }

    public function test_a_draft_without_text_in_the_base_language_is_not_sent(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Promo', ['message' => ['en' => 'Hello']]);
        $revision  = $this->revisionOf($broadcast);

        // The tenant changed its base language after the draft was saved.
        TenantSettings::fake(['content_base_language' => 'ru', 'available_languages' => ['ru', 'en']]);

        $this->actingAs($this->admin())
            ->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $revision])
            ->assertInertiaFlash('error');

        Bus::assertNotDispatched(RunBroadcastJob::class);
        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
    }

    public function test_a_draft_with_no_message_at_all_is_not_sent(): void
    {
        Bus::fake();
        $broadcast = $this->broadcast('Empty', ['message' => null]);

        $this->actingAs($this->admin())
            ->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($broadcast)])
            ->assertInertiaFlash('error');

        Bus::assertNotDispatched(RunBroadcastJob::class);
    }

    public function test_a_draft_whose_audience_is_gone_is_not_sent(): void
    {
        Bus::fake();
        $this->tag('vip');
        $this->actingAs($this->admin());

        $deletedSegment = $this->broadcast('Deleted segment', ['target_type' => 'segment', 'target_segment_id' => (string) Str::uuid()]);
        $foreignSegment = $this->broadcast('Foreign segment', ['target_type' => 'segment', 'target_segment_id' => (string) $this->segment('Foreign', self::OTHER_TENANT_ID)->getKey()]);
        $noSegment      = $this->broadcast('No segment', ['target_type' => 'segment', 'target_segment_id' => null]);
        $vanishedTag    = $this->broadcast('Vanished tag', ['target_type' => 'tags', 'target_tags' => ['vip', 'vanished']]);
        $noTags         = $this->broadcast('No tags', ['target_type' => 'tags', 'target_tags' => []]);

        foreach ([$deletedSegment, $foreignSegment, $noSegment, $vanishedTag, $noTags] as $broadcast) {
            $this->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($broadcast)])->assertInertiaFlash('error');

            $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status, $broadcast->name);
        }

        Bus::assertNotDispatched(RunBroadcastJob::class);
    }

    public function test_a_send_on_a_synchronous_queue_still_returns_to_the_list(): void
    {
        // No fake: the queue is `sync`, so the run executes inside the request and switches the tenant.
        $broadcast = $this->broadcast('Promo');

        $this->actingAs($this->admin())
            ->from($this->listUrl('?sort=name'))
            ->post($this->listUrl("/{$broadcast->getKey()}/send"), ['revision' => $this->revisionOf($broadcast)])
            ->assertRedirect($this->listUrl('?sort=name'))
            ->assertInertiaFlash('success');

        // Nobody to deliver to, so the run finished at once.
        $this->assertSame(BroadcastStatus::Completed, $broadcast->fresh()->status);
    }

    // ---- cancel and delete ---------------------------------------------------------------------------------------

    public function test_a_running_broadcast_is_cancelled_once(): void
    {
        $broadcast = $this->broadcast('Busy', ['status' => BroadcastStatus::Running->value]);

        $this->actingAs($this->admin());

        $this->post($this->listUrl("/{$broadcast->getKey()}/cancel"))->assertInertiaFlash('success');

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Cancelled, $broadcast->status);
        $this->assertNotNull($broadcast->completed_at);
        $completedAt = $broadcast->completed_at;

        $this->post($this->listUrl("/{$broadcast->getKey()}/cancel"))->assertInertiaFlash('error');

        $this->assertEquals($completedAt, $broadcast->fresh()->completed_at);
    }

    public function test_only_a_running_broadcast_can_be_cancelled(): void
    {
        $this->actingAs($this->admin());

        foreach ([BroadcastStatus::Draft, BroadcastStatus::Completed, BroadcastStatus::Failed] as $status) {
            $broadcast = $this->broadcast('Not running', ['status' => $status->value]);

            $this->post($this->listUrl("/{$broadcast->getKey()}/cancel"))->assertInertiaFlash('error');

            $broadcast->refresh();
            $this->assertSame($status, $broadcast->status);
            $this->assertNull($broadcast->completed_at);
        }
    }

    public function test_a_draft_and_a_finished_broadcast_are_deleted_and_a_running_one_is_not(): void
    {
        $draft     = $this->broadcast('Draft');
        $completed = $this->broadcast('Completed', ['status' => BroadcastStatus::Completed->value]);
        $running   = $this->broadcast('Running', ['status' => BroadcastStatus::Running->value]);

        $this->actingAs($this->admin())
            ->from($this->listUrl('?search=x'))
            ->delete($this->listUrl("/{$draft->getKey()}"))
            ->assertRedirect($this->listUrl('?search=x'))
            ->assertInertiaFlash('success');

        $this->delete($this->listUrl("/{$completed->getKey()}"))->assertInertiaFlash('success');
        $this->delete($this->listUrl("/{$running->getKey()}"))->assertInertiaFlash('error');

        $this->assertModelMissing($draft);
        $this->assertModelMissing($completed);
        $this->assertModelExists($running);
        $this->assertSame(BroadcastStatus::Running, $running->fresh()->status);
    }

    // ---- reach ---------------------------------------------------------------------------------------------------

    public function test_the_reach_is_what_the_run_would_resolve(): void
    {
        $channel = $this->channel();
        $vip     = $this->contact();
        ContactTag::query()->create(['contact_id' => $vip->getKey(), 'tag' => 'vip', 'tagged_by' => null, 'tagged_at' => now()]);
        $this->bind($vip, $channel);
        $this->bind($this->contact(), $channel);
        $segment = ContactSegment::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => 'VIP',
            'rules'     => ['match' => 'all', 'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]],
        ]);

        $this->actingAs($this->admin());

        $this->getJson($this->listUrl('/reach?target_type=all'))->assertOk()->assertExactJson(['count' => 2]);
        $this->getJson($this->listUrl('/reach?target_type=tags&target_tags[]=vip'))->assertOk()->assertExactJson(['count' => 1]);
        $this->getJson($this->listUrl("/reach?target_type=segment&target_segment_id={$segment->getKey()}"))->assertOk()->assertExactJson(['count' => 1]);

        $resolved = app(BroadcastRecipientResolver::class)->resolve((new Broadcast())->forceFill(['assistant_id' => (string) $this->assistant->getKey(), 'target_type' => 'all']));
        $this->assertCount(2, $resolved);
    }

    public function test_the_reach_validates_the_audience(): void
    {
        $this->actingAs($this->admin());

        $this->getJson($this->listUrl('/reach'))->assertUnprocessable()->assertJsonValidationErrors('target_type');
        $this->getJson($this->listUrl('/reach?target_type=tags'))->assertUnprocessable()->assertJsonValidationErrors('target_tags');
        $this->getJson($this->listUrl('/reach?target_type=tags&target_tags[]=nobody'))->assertUnprocessable()->assertJsonValidationErrors('target_tags.0');
        $this->getJson($this->listUrl('/reach?target_type=segment&target_segment_id=' . Str::uuid()))->assertUnprocessable()->assertJsonValidationErrors('target_segment_id');
    }

    public function test_the_reach_is_throttled(): void
    {
        $this->actingAs($this->admin());

        for ($i = 0; $i < 30; ++$i) {
            $this->getJson($this->listUrl('/reach?target_type=all'))->assertOk();
        }

        $this->getJson($this->listUrl('/reach?target_type=all'))->assertStatus(429);
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/broadcasts{$suffix}");
    }

    /**
     * What the list shows for the broadcast's revision, as a person looking at it would send it back.
     */
    private function revisionOf(Broadcast $broadcast): string
    {
        $revision = null;

        if (! auth()->check()) {
            $this->actingAs($this->admin());
        }

        $this->get($this->listUrl('?perPage=100'))->assertInertia(function (AssertableInertia $page) use ($broadcast, &$revision): void {
            foreach ($page->toArray()['props']['table']['rows'] as $row) {
                if ($row['id'] === (string) $broadcast->getKey()) {
                    $revision = $row['revision'];
                }
            }
        });

        $this->assertIsString($revision, 'The list shows no revision for this broadcast (is it a draft?).');

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name'        => 'Spring promo',
            'message'     => ['en' => 'Hello'],
            'target_type' => 'all',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function broadcast(string $name, array $attributes = [], ?Assistant $assistant = null): Broadcast
    {
        $assistant ??= $this->assistant;

        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $broadcast = Broadcast::query()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
            'name'         => $name,
            'message'      => ['en' => 'Hello'],
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Draft->value,
            ...$attributes,
        ]);

        if (null !== $createdAt) {
            $broadcast->forceFill(['created_at' => $createdAt])->save();
        }

        return $broadcast;
    }

    private function segment(string $name, string $tenantId = self::TENANT_ID): ContactSegment
    {
        return ContactSegment::query()->create([
            'tenant_id' => $tenantId,
            'name'      => $name,
            'rules'     => ['match' => 'all', 'conditions' => []],
        ]);
    }

    private function tag(string $tag): void
    {
        ContactTag::query()->create([
            'contact_id' => $this->contact()->getKey(),
            'tag'        => $tag,
            'tagged_by'  => null,
            'tagged_at'  => now(),
        ]);
    }

    private function channel(): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    private function contact(): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create();
    }

    private function bind(Contact $contact, Channel $channel): void
    {
        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function userWith(Permission $permission): User
    {
        $user = User::factory()->create();
        // Opening an assistant's console needs ManageAssistants; the broadcast permission is what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, $permission->value]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
