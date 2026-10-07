<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows\Pages;

use App\Filament\Assistant\Resources\Flows\FlowResource;
use App\Filament\Support\RecordLimit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListFlows extends ListRecords
{
    protected static string $resource = FlowResource::class;

    private ?RecordLimit $limit = null;

    public function getSubheading(): ?string
    {
        return $this->limit()->hintWhenReached();
    }

    protected function getHeaderActions(): array
    {
        return [
            // The policy cannot hide it from admins (Gate::before), so the limit is checked here too.
            CreateAction::make()->visible(fn (): bool => FlowResource::canCreateIgnoringLimit() && ! $this->limit()->reached),
        ];
    }

    /**
     * Read once per request: the subheading and the Create button share one count.
     */
    private function limit(): RecordLimit
    {
        return $this->limit ??= FlowResource::limit();
    }
}
