<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use App\Filament\Assistant\Resources\Broadcasts\Pages\EditBroadcast;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Filament\Support\Exceptions\Halt;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Feature\FeatureTestCase;

/**
 * Filament's broadcast form is open to drafts only. A started run reads the message for each recipient as it delivers,
 * so a text changed after the start would reach the rest of the audience in its new form.
 */
final class EditBroadcastTest extends FeatureTestCase
{
    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        Filament::setCurrentPanel('assistant');

        $this->assistant = Assistant::factory()->create();

        $user = User::factory()->create();
        $user->givePermissionTo([Permission::ManageAssistants->value, Permission::ManageBroadcast->value]);
        $user->assistants()->attach($this->assistant);

        $this->actingAs($user);
        Filament::setTenant($this->assistant);
    }

    public function test_a_draft_is_edited(): void
    {
        $broadcast = $this->broadcast(BroadcastStatus::Draft);

        Livewire::test(EditBroadcast::class, ['record' => $broadcast->getKey()])
            ->fillForm(['name' => 'Renamed', 'message' => ['en' => 'New text'], 'target_type' => 'all'])
            ->call('save')
            ->assertHasNoFormErrors();

        $broadcast->refresh();
        $this->assertSame('Renamed', $broadcast->name);
        $this->assertEquals(['en' => 'New text'], $broadcast->message);
    }

    public function test_a_broadcast_that_is_no_longer_a_draft_cannot_be_edited(): void
    {
        foreach ([BroadcastStatus::Running, BroadcastStatus::Completed, BroadcastStatus::Cancelled, BroadcastStatus::Failed] as $status) {
            $broadcast = $this->broadcast($status);

            $this->assertFalse(BroadcastResource::canEdit($broadcast), $status->value);

            Livewire::test(EditBroadcast::class, ['record' => $broadcast->getKey()])->assertForbidden();
        }
    }

    public function test_a_run_that_starts_while_the_form_is_open_is_not_changed_by_saving_it(): void
    {
        $broadcast = $this->broadcast(BroadcastStatus::Draft);

        $component = Livewire::test(EditBroadcast::class, ['record' => $broadcast->getKey()])
            ->fillForm(['name' => 'Renamed after the start', 'message' => ['en' => 'Changed after the start'], 'target_type' => 'all']);

        // Another request starts the run while the form is still open.
        Broadcast::query()->whereKey($broadcast->getKey())->update(['status' => BroadcastStatus::Running->value]);

        $component->call('save');

        $broadcast->refresh();
        $this->assertSame('Original', $broadcast->name);
        $this->assertEquals(['en' => 'Original text'], $broadcast->message);
        $this->assertSame(BroadcastStatus::Running, $broadcast->status);
    }

    public function test_the_write_itself_refuses_a_broadcast_that_started_after_the_page_checked(): void
    {
        // The page checks the status when it loads the record; a run can start between that check and the write, so
        // the write looks again under a lock. This is that second look, with the record the page still holds as a draft.
        $held = $this->broadcast(BroadcastStatus::Draft);
        Broadcast::query()->whereKey($held->getKey())->update(['status' => BroadcastStatus::Running->value]);

        $write = new ReflectionMethod(EditBroadcast::class, 'handleRecordUpdate');

        try {
            $write->invoke(new EditBroadcast(), $held, ['name' => 'Hijacked', 'message' => ['en' => 'Changed after the start']]);
            $this->fail('The write went ahead on a broadcast that was no longer a draft.');
        } catch (Halt) {
            // Refused, as it should be.
        }

        $held->refresh();
        $this->assertSame('Original', $held->name);
        $this->assertEquals(['en' => 'Original text'], $held->message);
    }

    private function broadcast(BroadcastStatus $status): Broadcast
    {
        return Broadcast::query()->create([
            'tenant_id'    => $this->assistant->tenant_id,
            'assistant_id' => $this->assistant->getKey(),
            'name'         => 'Original',
            'message'      => ['en' => 'Original text'],
            'target_type'  => 'all',
            'status'       => $status->value,
        ]);
    }
}
