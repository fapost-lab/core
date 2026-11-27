<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientFailureReason;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The report of one broadcast on the Inertia console: its outcomes counted over its recipients, the recipients one by
 * one with a failure reason that is a category and never the stored error text, behind the broadcasts screen's policy.
 */
final class BroadcastReportConsoleTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    /** What a provider's exception message can look like: the request URL, with the bot token in its path. */
    private const string SECRET_ERROR = 'cURL error 28 for https://api.telegram.org/bot123456:SECRET-TOKEN/sendMessage';

    private Assistant $assistant;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create();
        $this->channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    public function test_the_report_counts_the_outcomes_over_the_recipients_and_lists_each_one(): void
    {
        $broadcast = $this->broadcast(BroadcastStatus::Running);
        $ada       = $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->recipient($broadcast, $ada, RecipientStatus::Sent, sentAt: Carbon::now());
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Pending);
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Pending);
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Failed, self::SECRET_ERROR);
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Skipped, BroadcastStatus::STOP_REASON_RUN_FAILED);

        $response = $this->actingAs($this->admin())->get($this->reportUrl($broadcast));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Broadcasts/Show')
                ->where('broadcast.id', (string) $broadcast->getKey())
                ->where('broadcast.status', 'running')
                ->where('outcomes', ['pending' => 2, 'sent' => 1, 'failed' => 1, 'skipped' => 1, 'total' => 5])
                ->where('table.meta.total', 5)
                ->where('statuses', ['pending', 'sent', 'failed', 'skipped'])
                ->where('urls', [
                    'index' => "/assistant/{$this->assistant->getKey()}/broadcasts",
                    'show'  => "/assistant/{$this->assistant->getKey()}/broadcasts/{$broadcast->getKey()}",
                ])
                ->etc());

        $rows = collect($this->props($response)['table']['rows']);
        $sent = $rows->firstWhere('status', 'sent');

        $this->assertSame('Ada Lovelace', $sent['contact']);
        $this->assertSame($ada->external_id, $sent['externalId']);
        $this->assertNull($sent['reason']);
        $this->assertNotNull($sent['sentAt']);
        $this->assertSame("/assistant/{$this->assistant->getKey()}/contacts/{$ada->getKey()}", $sent['contactUrl']);
        $this->assertSame(RecipientFailureReason::DeliveryFailed->value, $rows->firstWhere('status', 'failed')['reason']);
        $this->assertSame(RecipientFailureReason::RunFailed->value, $rows->firstWhere('status', 'skipped')['reason']);
    }

    public function test_the_stored_error_text_never_reaches_the_page(): void
    {
        $broadcast = $this->broadcast(BroadcastStatus::Completed);
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Failed, self::SECRET_ERROR);

        $response = $this->actingAs($this->admin())->get($this->reportUrl($broadcast))->assertOk();

        $page = (string) json_encode($this->props($response));

        $this->assertStringNotContainsString('SECRET-TOKEN', $page);
        $this->assertStringNotContainsString('api.telegram.org', $page);
    }

    public function test_the_recipients_can_be_narrowed_to_one_outcome(): void
    {
        $broadcast = $this->broadcast(BroadcastStatus::Completed);
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Sent, sentAt: Carbon::now());
        $failed = $this->recipient($broadcast, $this->contact(), RecipientStatus::Failed, RecipientFailureReason::CONTACT_MISSING_ERROR);

        $this->actingAs($this->admin())
            ->get($this->reportUrl($broadcast, '?filter[status]=failed'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $failed->getKey())
                ->where('table.rows.0.reason', RecipientFailureReason::ContactMissing->value)
                ->where('table.state.filters', ['status' => 'failed'])
                // The totals are the broadcast's, whatever the table shows.
                ->where('outcomes.total', 2)
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->reportUrl($broadcast, '?filter[status]=bogus'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 2)
                ->where('table.state.filters', [])
                ->etc());
    }

    public function test_a_draft_has_nothing_to_report(): void
    {
        $draft = $this->broadcast(BroadcastStatus::Draft);

        $this->actingAs($this->admin())
            ->get($this->reportUrl($draft))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('broadcast.status', 'draft')
                ->where('outcomes', ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0])
                ->where('table.meta.total', 0)
                ->etc());
    }

    public function test_the_list_links_a_run_to_its_report_and_a_draft_to_none(): void
    {
        $run   = $this->broadcast(BroadcastStatus::Completed, 'Run');
        $draft = $this->broadcast(BroadcastStatus::Draft, 'Draft');
        $rows  = [];

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/broadcasts"))
            ->assertInertia(function (AssertableInertia $page) use (&$rows): void {
                $rows = collect($page->toArray()['props']['table']['rows'])->keyBy('id')->all();
            });

        $this->assertSame("/assistant/{$this->assistant->getKey()}/broadcasts/{$run->getKey()}", $rows[(string) $run->getKey()]['reportUrl']);
        $this->assertNull($rows[(string) $draft->getKey()]['reportUrl']);
    }

    public function test_a_broadcast_of_another_assistant_or_tenant_is_not_found(): void
    {
        $otherAssistant = $this->broadcast(BroadcastStatus::Completed, assistant: Assistant::factory()->create());
        $otherTenant    = $this->broadcast(BroadcastStatus::Completed, assistant: Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));
        $admin          = $this->admin();

        $this->actingAs($admin)->get($this->reportUrl($otherAssistant))->assertNotFound();
        $this->actingAs($admin)->get($this->reportUrl($otherTenant))->assertNotFound();
        $this->actingAs($admin)->get($this->panelUrl("/assistant/{$this->assistant->getKey()}/broadcasts/not-a-ulid"))->assertNotFound();
    }

    public function test_the_report_needs_the_broadcast_permission(): void
    {
        $broadcast = $this->broadcast(BroadcastStatus::Completed);
        $this->recipient($broadcast, $this->contact(), RecipientStatus::Sent, sentAt: Carbon::now());

        $this->actingAs($this->userWith([]))->get($this->reportUrl($broadcast))->assertForbidden();

        // With the permission the report opens; a contact's card is linked only for whoever may open contacts.
        $this->actingAs($this->userWith([Permission::ManageBroadcast]))
            ->get($this->reportUrl($broadcast))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('table.rows.0.contactUrl', null)->etc());

        $this->actingAs($this->userWith([Permission::ManageBroadcast, Permission::ViewContacts]))
            ->get($this->reportUrl($broadcast))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->whereType('table.rows.0.contactUrl', 'string')->etc());
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $props = [];

        $response->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    private function reportUrl(Broadcast $broadcast, string $suffix = ''): string
    {
        // Always inside this assistant's console: a broadcast of another one must not be found from here.
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/broadcasts/{$broadcast->getKey()}{$suffix}");
    }

    private function broadcast(BroadcastStatus $status, string $name = 'Spring sale', ?Assistant $assistant = null): Broadcast
    {
        $assistant ??= $this->assistant;

        return Broadcast::query()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
            'name'         => $name,
            'message'      => ['en' => 'Hello'],
            'target_type'  => 'all',
            'status'       => $status->value,
            'started_at'   => BroadcastStatus::Draft === $status ? null : Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function contact(array $meta = []): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create(['meta' => $meta]);
    }

    private function recipient(Broadcast $broadcast, Contact $contact, RecipientStatus $status, ?string $error = null, ?Carbon $sentAt = null): BroadcastRecipient
    {
        return BroadcastRecipient::query()->create([
            'tenant_id'    => $broadcast->tenant_id,
            'broadcast_id' => $broadcast->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $this->channel->getKey(),
            'status'       => $status->value,
            'error'        => $error,
            'sent_at'      => $sentAt,
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        // Being assigned opens the assistant's console; the report asks for the broadcast permission on top.
        $user->givePermissionTo(array_map(static fn (Permission $permission): string => $permission->value, $permissions));
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
