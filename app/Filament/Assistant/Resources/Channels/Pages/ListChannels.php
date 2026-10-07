<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Pages;

use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use App\Filament\Assistant\Resources\Channels\Tables\ChannelsTable;
use App\Filament\Support\RecordLimit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

final class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    protected ChannelServiceInterface $channelService;

    private ?RecordLimit $limit = null;

    public function boot(ChannelServiceInterface $channelService): void
    {
        $this->channelService = $channelService;
    }

    public function table(Table $table): Table
    {
        return ChannelsTable::configureRecordActions($table, $this->channelService);
    }

    public function getSubheading(): ?string
    {
        return $this->limit()->hintWhenReached();
    }

    protected function getHeaderActions(): array
    {
        return [
            // The policy cannot hide it from admins (Gate::before), so the limit is checked here too.
            CreateAction::make()->visible(fn (): bool => ChannelResource::canCreateIgnoringLimit() && ! $this->limit()->reached),
        ];
    }

    /**
     * Read once per request: the subheading and the Create button share one count.
     */
    private function limit(): RecordLimit
    {
        return $this->limit ??= ChannelResource::limit();
    }
}
