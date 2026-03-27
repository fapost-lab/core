<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Pages;

use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Models\Channel;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EditChannel extends EditRecord
{
    protected static string $resource = ChannelResource::class;

    protected ChannelServiceInterface $channelService;

    public function boot(ChannelServiceInterface $channelService): void
    {
        $this->channelService = $channelService;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ('' === ($data['token'] ?? '')) {
            unset($data['token']);
        }

        if ('' === ($data['secret_token'] ?? '')) {
            unset($data['secret_token']);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ( ! $record instanceof Channel) {
            throw new InvalidArgumentException('Expected channel record.');
        }

        $this->channelService->update($record, $data);

        return $record->refresh();
    }
}
