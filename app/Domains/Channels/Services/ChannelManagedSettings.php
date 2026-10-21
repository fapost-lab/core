<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use Fapost\Foundation\Channel\Settings\InvalidManagedSettingException;
use Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

/**
 * Stores the values an integration read from the provider for the managed fields of its channel's schema: checked
 * against the schema like the form's values, merged into the stored `config` under a row lock, and written past the
 * model's events, so the provider is not synchronized again because the integration told a name.
 *
 * It is the only way a managed value is written, for the built-in integrations and the connectors alike.
 */
final readonly class ChannelManagedSettings implements ManagedChannelSettingsInterface
{
    public function __construct(
        private ChannelRegistryInterface $registry,
        private ValidationFactory $validation,
    ) {
    }

    public function report(string $channelId, array $values): void
    {
        // The channel is read under a row lock, so two writers of managed values do not interleave. A form save does
        // not take the lock: one racing with this write may drop the value, which the next report restores.
        Channel::query()->getConnection()->transaction(function () use ($channelId, $values): void {
            $channel = Channel::query()->whereKey($channelId)->lockForUpdate()->first();

            // Gone while the job ran: nothing to report to.
            if (! $channel instanceof Channel) {
                return;
            }

            $schema = $this->registry->profile($channel->type)->settings();

            foreach ($values as $key => $value) {
                $key   = (string) $key;
                $field = $schema->field($key);

                if (null === $field || ! $field->managed) {
                    throw new InvalidManagedSettingException("'{$key}' is not a managed setting of the '{$channel->type}' channel.");
                }

                if (null !== $value) {
                    $this->assertFits($channel->type, $key, $value, ChannelSettingRules::for($field, withRequired: false));
                    $values[$key] = ChannelSettingRules::cast($field, $value);
                }
            }

            $stored = is_array($channel->config) ? $channel->config : [];
            $merged = [...$stored, ...$values];
            $config = [];

            // A null removes the key.
            foreach ($values as $key => $value) {
                if (null === $value) {
                    unset($merged[$key]);
                }
            }

            // Only what the schema names, in its order, as the form save keeps it: an unchanged config stays
            // byte-identical and saving the form does not look like a change to the webhook.
            foreach ($schema->fields as $field) {
                if (array_key_exists($field->key, $merged)) {
                    $config[$field->key] = $merged[$field->key];
                }
            }

            if ($config === $stored) {
                return;
            }

            Channel::query()->whereKey($channelId)->update(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        });
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     */
    private function assertFits(string $type, string $key, mixed $value, array $rules): void
    {
        $validator = $this->validation->make(['config' => [$key => $value]], $rules);

        if ($validator->fails()) {
            throw new InvalidManagedSettingException("The value of '{$key}' does not fit the '{$type}' channel's setting: {$validator->errors()->first()}");
        }
    }
}
