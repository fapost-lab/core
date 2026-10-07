<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use App\Filament\Support\ChecksRecordLimitOnMount;
use App\Filament\Support\RecordLimit;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class CreateChannel extends CreateRecord
{
    use ChecksRecordLimitOnMount;

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

        // Admins pass the policy, so the page is closed here when the tenant is at its channel limit,
        // on mount only (see ChecksRecordLimitOnMount).
        abort_if($this->mounting && ChannelResource::isLimitReached(), 403);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $owner = $this->currentAssistant->get();

        if (! $owner instanceof Assistant) {
            throw new InvalidArgumentException('Current assistant must be an Assistant model.');
        }

        try {
            return $this->channelService->create($owner, $this->normalizeConfigPayload($data));
        } catch (RecordLimitReachedException $e) {
            RecordLimit::notifyReached($e, 'staff.channels.limit');

            throw new Halt();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function normalizeConfigPayload(array $data): array
    {
        $type = $data['type'] ?? null;
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
