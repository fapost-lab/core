<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Assistant;

use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Filament\Assistant\Resources\Channels\Tables\ChannelsTable;
use Filament\Actions\EditAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

final class ChannelsTableTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_edit_action_uses_channel_service_for_updates(): void
    {
        $livewire = Mockery::mock(HasTable::class);
        $service  = Mockery::mock(ChannelServiceInterface::class);
        $table    = ChannelsTable::configureRecordActions(Table::make($livewire), $service);

        $editAction = collect($table->getRecordActions())
            ->first(fn (mixed $action): bool => $action instanceof EditAction);

        $this->assertInstanceOf(EditAction::class, $editAction);

        $using = new ReflectionProperty($editAction, 'using');
        $using->setAccessible(true);

        $this->assertNotNull($using->getValue($editAction));
    }
}
