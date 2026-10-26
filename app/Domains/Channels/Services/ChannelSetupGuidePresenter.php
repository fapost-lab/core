<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use Fapost\Foundation\Channel\Settings\ChannelSetupGuideInterface;
use Fapost\Foundation\Channel\Settings\ChannelSetupStep;

/**
 * The setup guide of a channel type as a person reads it, in the interface language.
 *
 * A profile that implements {@see ChannelSetupGuideInterface} names its steps in English and may add a translation
 * prefix ({@see ChannelSetupStep::$translationKey}): `{prefix}.title` and `{prefix}.text` are looked up in Core's
 * translations (a connector adds its own lines with `loadTranslationsFrom`), and the English text of the step stands in
 * for any that is missing. A profile without a guide has no steps.
 */
final readonly class ChannelSetupGuidePresenter
{
    public function __construct(
        private ChannelRegistryInterface $registry,
        private WebhookUrlGenerator $webhookUrls,
    ) {
    }

    /**
     * The steps for a channel that does not exist yet: nothing is known of its webhook URL or config.
     *
     * @return list<array{title: string, text: string|null, copy: string|null, copyKind: string}>
     */
    public function forType(string $type): array
    {
        return $this->steps($type, null, []);
    }

    /**
     * The steps for an existing channel, with its public webhook URL (the gateway's or Laravel's, as for any channel)
     * and its stored config.
     *
     * @return list<array{title: string, text: string|null, copy: string|null, copyKind: string}>
     */
    public function forChannel(Channel $channel): array
    {
        $hash = $channel->webhook_public_hash;

        return $this->steps(
            $channel->type,
            is_string($hash) && '' !== $hash ? $this->webhookUrls->forChannel($channel->type, $hash) : null,
            is_array($channel->config) ? $channel->config : [],
        );
    }

    private static function translated(ChannelSetupStep $step, string $part): ?string
    {
        if (null === $step->translationKey) {
            return null;
        }

        $key  = "{$step->translationKey}.{$part}";
        $text = trans($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<array{title: string, text: string|null, copy: string|null, copyKind: string}>
     */
    private function steps(string $type, ?string $webhookUrl, array $config): array
    {
        $profile = $this->registry->profile($type);

        if (! $profile instanceof ChannelSetupGuideInterface) {
            return [];
        }

        return array_map(
            static fn (ChannelSetupStep $step): array => [
                'title'    => self::translated($step, 'title') ?? $step->title,
                'text'     => null === $step->text ? null : (self::translated($step, 'text') ?? $step->text),
                'copy'     => $step->copy,
                'copyKind' => $step->copyKind->value,
            ],
            $profile->setupGuide($webhookUrl, $config),
        );
    }
}
