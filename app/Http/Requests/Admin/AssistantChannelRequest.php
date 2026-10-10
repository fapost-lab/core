<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\AssistantChannelService;
use App\Domains\Staff\Models\User;
use App\Http\Requests\Console\ChannelFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Changing a channel from the admin panel, under the assistant in the URL (`record`). The rules are the console's
 * ({@see ChannelFieldRules}) for the channel's stored type, which never changes.
 */
final class AssistantChannelRequest extends FormRequest
{
    private ?Channel $channel = null;

    /**
     * Needs `view` on the assistant (seeing it in the admin panel) and `update` on the channel. An assistant the user
     * may not see, or a channel of another assistant, is a 404.
     */
    public function authorize(StaffAssistantService $assistants, AssistantChannelService $channels): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $assistant = $assistants->findFor($user, (string) $this->route('record'));

        return $user->can('view', $assistant) && $user->can('update', $this->channel($channels, $assistant));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ChannelFieldRules::rules($this->resolvedChannel()->type, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return ChannelFieldRules::fields($this->validated(), $this->resolvedChannel()->type, false);
    }

    private function channel(AssistantChannelService $channels, Assistant $assistant): Channel
    {
        return $this->channel ??= $channels->findFor($assistant, (string) $this->route('channel'));
    }

    /**
     * The channel loaded while authorizing, which always runs first.
     */
    private function resolvedChannel(): Channel
    {
        return $this->channel ?? throw new LogicException('The channel is resolved while authorizing.');
    }
}
