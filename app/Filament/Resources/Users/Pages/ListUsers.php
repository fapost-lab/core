<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\RecordLimit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    private ?RecordLimit $limit = null;

    public function getSubheading(): ?string
    {
        return $this->limit()->hintWhenReached();
    }

    protected function getHeaderActions(): array
    {
        return [
            // The policy cannot hide it from admins (Gate::before), so the limit is checked here too.
            CreateAction::make()->visible(fn (): bool => UserResource::canCreateIgnoringLimit() && ! $this->limit()->reached),
        ];
    }

    /**
     * Read once per request: the subheading and the Create button share one count.
     */
    private function limit(): RecordLimit
    {
        return $this->limit ??= UserResource::limit();
    }
}
