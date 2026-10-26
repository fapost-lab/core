<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;
use App\Http\Requests\Console\ChannelFieldRules;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The channel of the setup wizard's optional step: a new channel for the first assistant the user may open. The rules
 * are the channel form's ({@see ChannelFieldRules}, creating), whatever the provider.
 */
final class OnboardingChannelRequest extends FormRequest
{
    public function authorize(AccessibleAssistants $assistants): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $assistant = $assistants->for($user)->first();

        return null !== $assistant && $user->can('create', [Channel::class, $assistant]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ChannelFieldRules::rules($this->channelType(), true);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ChannelFieldRules::attributes($this->channelType());
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        // Validation has passed, so the type is a registered one.
        return ChannelFieldRules::fields($this->validated(), $this->channelType() ?? throw new LogicException('The channel type is unknown.'), true);
    }

    private function channelType(): ?string
    {
        $type = $this->input('type');

        return is_string($type) && $this->container->make(ChannelRegistryInterface::class)->has($type) ? $type : null;
    }
}
