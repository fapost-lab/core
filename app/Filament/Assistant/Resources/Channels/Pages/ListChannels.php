<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Pages;

use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use App\Filament\Assistant\Resources\Channels\Tables\ChannelsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

final class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    protected ChannelServiceInterface $channelService;

    public function boot(ChannelServiceInterface $channelService): void
    {
        $this->channelService = $channelService;
    }

    public function table(Table $table): Table
    {
        return ChannelsTable::configureRecordActions($table, $this->channelService);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
