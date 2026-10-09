<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\AssistantChannelService;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The fields of a channel, for creating one (no `record` in the route) and for changing one. The rules follow the
 * channel's type, see {@see ChannelFieldRules}; a changed channel keeps its type whatever the request says.
 */
final class ChannelRequest extends FormRequest
{
    private ?Channel $channel = null;

    /**
     * Changing needs `update` on the channel in the route (a channel of another assistant is a 404), creating needs
     * `create` for the assistant in the URL.
     */
    public function authorize(AssistantChannelService $channels, CurrentAssistantInterface $assistant): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', [Channel::class, $assistant->get()]);
        }

        return $user->can('update', $this->channel($channels, $assistant));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(AssistantChannelService $channels, CurrentAssistantInterface $assistant): array
    {
        return ChannelFieldRules::rules($this->channelType($channels, $assistant), null === $this->route('record'));
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        // Called by the controller, so nothing is injected here. Validation has passed: a created channel has a known type.
        $type = $this->channelType($this->container->make(AssistantChannelService::class), $this->container->make(CurrentAssistantInterface::class)) ?? throw new LogicException('The channel type is unknown.');

        return ChannelFieldRules::fields($this->validated(), $type, null === $this->route('record'));
    }

    /**
     * The channel in the route, loaded once for authorizing, validating and building the fields.
     */
    private function channel(AssistantChannelService $channels, CurrentAssistantInterface $assistant): Channel
    {
        return $this->channel ??= $channels->findFor($assistant->get(), (string) $this->route('record'));
    }

    private function channelType(AssistantChannelService $channels, CurrentAssistantInterface $assistant): ?ChannelTypeEnum
    {
        if (null === $this->route('record')) {
            $type = $this->input('type');

            return is_string($type) ? ChannelTypeEnum::tryFrom($type) : null;
        }

        return $this->channel($channels, $assistant)->type;
    }
}
