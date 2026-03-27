<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Pages;

use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Channel;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CreateChannel extends CreateRecord
{
    protected static string $resource = ChannelResource::class;

    protected CurrentAssistantInterface $currentAssistant;

    protected ChannelServiceInterface $channelService;

    public function boot(
        CurrentAssistantInterface $currentAssistant,
        ChannelServiceInterface $channelService,
    ): void {
        $this->currentAssistant = $currentAssistant;
        $this->channelService   = $channelService;
    }

    protected function authorizeAccess(): void
    {
        Gate::authorize('create', [Channel::class, $this->currentAssistant->get()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $owner = $this->currentAssistant->get();

        if ( ! $owner instanceof Assistant) {
            throw new InvalidArgumentException('Current assistant must be an Assistant model.');
        }

        return $this->channelService->create($owner, $data);
    }
}
