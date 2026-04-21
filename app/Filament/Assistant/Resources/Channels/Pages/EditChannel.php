<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Pages;

use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data['config']) && is_array($data['config'])) {
            $data['config_kv'] = $data['config'];
        }

        return $data;
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

        return $this->normalizeConfigPayload($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ( ! $record instanceof Channel) {
            throw new InvalidArgumentException('Expected channel record.');
        }

        $this->channelService->update($record, $data);

        return $record->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeConfigPayload(array $data): array
    {
        $type = $data['type'] ?? $this->record?->type ?? null;
        $type = $type instanceof ChannelTypeEnum ? $type->value : (is_string($type) ? $type : null);

        if (ChannelTypeEnum::Telegram->value === $type) {
            unset($data['config_kv']);

            return $data;
        }

        if (isset($data['config_kv']) && is_array($data['config_kv'])) {
            $data['config'] = $data['config_kv'];
        }

        unset($data['config_kv']);

        return $data;
    }
}
