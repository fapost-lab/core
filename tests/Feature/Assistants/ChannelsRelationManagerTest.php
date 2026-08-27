<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Domains\Assistant\Models\Assistant;
use App\Filament\Resources\Assistants\Pages\EditAssistant;
use App\Filament\Resources\Assistants\RelationManagers\ChannelsRelationManager;
use Filament\Tables\Table;
use Tests\Feature\FeatureTestCase;

final class ChannelsRelationManagerTest extends FeatureTestCase
{
    public function test_admin_assistant_relation_manager_does_not_offer_channel_creation_action(): void
    {
        $relationManager              = app(ChannelsRelationManager::class);
        $relationManager->ownerRecord = Assistant::factory()->make();
        $relationManager->pageClass   = EditAssistant::class;

        $table = $relationManager->table(Table::make($relationManager));

        $this->assertSame([], $table->getHeaderActions());
    }
}
