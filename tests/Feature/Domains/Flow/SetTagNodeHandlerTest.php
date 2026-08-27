<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Contact\Repositories\ContactTagRepository;
use App\Domains\Flow\Handlers\SetTagNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class SetTagNodeHandlerTest extends FeatureTestCase
{
    private SetTagNodeHandler $handler;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler   = new SetTagNodeHandler(
            new ContactTagRepository(),
            $this->app->make(TemplateRenderer::class),
        );
        $this->sessionId = (string) Str::uuid();
    }

    public function test_add_creates_tag_rows_with_session_as_tagged_by(): void
    {
        $contact = Contact::factory()->create();

        $result = $this->handler->execute(
            $this->node('add', ['paid', 'vip']),
            [],
            $this->context($contact->id),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);

        $tags = ContactTag::query()->where('contact_id', $contact->id)->get();
        $this->assertCount(2, $tags);
        $this->assertEqualsCanonicalizing(['paid', 'vip'], $tags->pluck('tag')->all());
        $this->assertTrue($tags->every(fn (ContactTag $t): bool => $t->tagged_by === $this->sessionId));
    }

    public function test_add_is_idempotent_across_retries(): void
    {
        $contact = Contact::factory()->create();

        $this->handler->execute($this->node('add', ['lead']), [], $this->context($contact->id));
        $this->handler->execute($this->node('add', ['lead']), [], $this->context($contact->id));

        $this->assertSame(1, ContactTag::query()->where('contact_id', $contact->id)->count());
    }

    public function test_remove_deletes_tag_and_is_safe_when_absent(): void
    {
        $contact = Contact::factory()->create();
        ContactTag::factory()->create(['contact_id' => $contact->id, 'tag' => 'lead']);

        $this->handler->execute($this->node('remove', ['lead']), [], $this->context($contact->id));
        // Second run on an already-removed tag must not error.
        $this->handler->execute($this->node('remove', ['lead']), [], $this->context($contact->id));

        $this->assertSame(0, ContactTag::query()->where('contact_id', $contact->id)->count());
    }

    public function test_toggle_flips_presence(): void
    {
        $contact = Contact::factory()->create();

        $this->handler->execute($this->node('toggle', ['vip']), [], $this->context($contact->id));
        $this->assertTrue(ContactTag::query()->where('contact_id', $contact->id)->where('tag', 'vip')->exists());

        $this->handler->execute($this->node('toggle', ['vip']), [], $this->context($contact->id));
        $this->assertFalse(ContactTag::query()->where('contact_id', $contact->id)->where('tag', 'vip')->exists());
    }

    public function test_first_execution_emits_idempotency_marker_state_change(): void
    {
        $contact = Contact::factory()->create();

        $result = $this->handler->execute($this->node('toggle', ['vip']), [], $this->context($contact->id));

        $this->assertSame(['system.set_tag.node-tag' => true], $result->stateChanges);
    }

    public function test_replay_with_marker_skips_all_mutations(): void
    {
        $contact = Contact::factory()->create();
        $state   = ['system' => ['set_tag' => ['node-tag' => true]]];

        // A retry under the session lock re-executes the node with the marker
        // already persisted: toggle must not flip the tag again.
        $result = $this->handler->execute($this->node('toggle', ['vip']), $state, $this->context($contact->id));

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertTrue($result->metadata['replayed']);
        $this->assertSame([], $result->stateChanges);
        $this->assertSame(0, ContactTag::query()->where('contact_id', $contact->id)->count());
    }

    public function test_distinct_tags_returns_sorted_unique_vocabulary(): void
    {
        $a = Contact::factory()->create();
        $b = Contact::factory()->create();
        ContactTag::factory()->create(['contact_id' => $a->id, 'tag' => 'vip']);
        ContactTag::factory()->create(['contact_id' => $a->id, 'tag' => 'lead']);
        ContactTag::factory()->create(['contact_id' => $b->id, 'tag' => 'vip']);

        $this->assertSame(['lead', 'vip'], (new ContactTagRepository())->distinctTags());
    }

    public function test_sync_for_contact_adds_and_removes_to_match_desired_set(): void
    {
        $contact = Contact::factory()->create();
        $repo    = new ContactTagRepository();
        $repo->add($contact->id, 'old', null);
        $repo->add($contact->id, 'keep', null);

        $repo->syncForContact($contact->id, ['keep', 'new', '  ', 'new'], 'staff-7');

        $rows = ContactTag::query()->where('contact_id', $contact->id)->get();
        $this->assertEqualsCanonicalizing(['keep', 'new'], $rows->pluck('tag')->all());
        // Newly added row is attributed to the acting staff user.
        $this->assertSame('staff-7', $rows->firstWhere('tag', 'new')?->tagged_by);
    }

    public function test_templated_tags_resolve_and_blank_values_are_dropped(): void
    {
        $contact = Contact::factory()->create();

        $result = $this->handler->execute(
            $this->node('add', ['{{flow.segment}}', '   ', 'static']),
            ['flow' => ['segment' => 'premium']],
            $this->context($contact->id),
        );

        $this->assertEqualsCanonicalizing(['premium', 'static'], $result->metadata['tags']);
        $this->assertEqualsCanonicalizing(
            ['premium', 'static'],
            ContactTag::query()->where('contact_id', $contact->id)->pluck('tag')->all(),
        );
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    private function node(string $action, array $tags): array
    {
        return [
            'id'     => 'node-tag',
            'config' => ['action' => $action, 'tags' => $tags],
        ];
    }

    private function context(string $contactId): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: '00000000-0000-0000-0000-000000000001',
            contactId: $contactId,
            sessionId: $this->sessionId,
            nodeId: 'node-tag',
            idempotencyKey: 'idem-tag',
            platform: 'telegram',
        );
    }
}
