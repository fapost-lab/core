<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\InputExpectedType;
use App\Domains\Flow\Enums\SendMessageContentType;
use Fapost\Foundation\Flow\Enums\KeyboardMode;
use Fapost\Foundation\Messaging\ChannelCapability;

/**
 * Finds what a flow asks of a channel that the assistant's channels do not declare they can do.
 *
 * Every channel of the assistant counts, active or not: the author may switch one on later. The result is advice,
 * never an error. One warning names a node, a capability and every channel type that lacks it. The registry is a
 * singleton without tenant data, so the checker keeps no state between calls.
 */
final readonly class ChannelCapabilityChecker
{
    public const string CODE = 'channel_lacks_capability';

    public function __construct(private ChannelRegistryInterface $channelRegistry)
    {
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap
     *
     * @return list<FlowValidationErrorDto>
     */
    public function check(array $nodeMap, string $assistantId): array
    {
        $channelTypes = $this->channelTypes($assistantId);

        if ([] === $channelTypes) {
            return [];
        }

        $warnings = [];

        foreach ($nodeMap as $nodeId => $node) {
            foreach ($this->requiredCapabilities($node) as $capability) {
                $lacking = [];

                foreach ($channelTypes as $type) {
                    if (! $this->channelRegistry->capabilities($type)->supports($capability)) {
                        $lacking[] = $this->channelRegistry->label($type);
                    }
                }

                if ([] === $lacking) {
                    continue;
                }

                $warnings[] = new FlowValidationErrorDto(
                    path: "nodes.{$nodeId}",
                    code: self::CODE,
                    message: sprintf('%s cannot %s.', implode(', ', $lacking), $this->describe($capability)),
                );
            }
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function channelTypes(string $assistantId): array
    {
        $types = [];

        foreach (Channel::query()->where('assistant_id', $assistantId)->get(['id', 'type']) as $channel) {
            $types[$channel->getType()] = true;
        }

        return array_keys($types);
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return list<ChannelCapability>
     */
    private function requiredCapabilities(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return match ($node['type'] ?? null) {
            'send_message' => $this->sendMessageCapabilities($config),
            'input'        => $this->inputCapabilities($config),
            default        => [],
        };
    }

    /**
     * Mirrors what the send_message handler and FlowMessageSender do with the node's keyboard.
     *
     * @param  array<string, mixed>  $config
     *
     * @return list<ChannelCapability>
     */
    private function sendMessageCapabilities(array $config): array
    {
        if (SendMessageContentType::TextWithKeyboard->value !== ($config['content_type'] ?? null)) {
            return [];
        }

        $buttons = is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [];

        // Dynamic buttons are built from a variable at runtime, so the node has a keyboard even without static ones.
        if ([] === $buttons && ! is_array($config['dynamic_buttons'] ?? null)) {
            return [];
        }

        $mode = KeyboardMode::tryFrom(is_string($config['keyboard_mode'] ?? null) ? $config['keyboard_mode'] : '')
            ?? KeyboardMode::Inline;

        if (KeyboardMode::Inline === $mode) {
            return [
                ChannelCapability::CallbackButtons,
                true === ($config['remove_keyboard_after_press'] ?? true)
                    ? ChannelCapability::RemoveButtons
                    : ChannelCapability::PersistentButtons,
            ];
        }

        $needed = [] === $buttons ? [ChannelCapability::ReplyButtons->value => ChannelCapability::ReplyButtons] : [];

        foreach ($buttons as $button) {
            $capability = match (is_array($button) ? ($button['special'] ?? null) : null) {
                'request_contact'  => ChannelCapability::ShareContact,
                'request_location' => ChannelCapability::ShareLocation,
                default            => ChannelCapability::ReplyButtons,
            };

            $needed[$capability->value] = $capability;
        }

        return array_values($needed);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<ChannelCapability>
     */
    private function inputCapabilities(array $config): array
    {
        $type = is_string($config['expected_type'] ?? null) ? InputExpectedType::tryFrom($config['expected_type']) : null;

        return match ($type) {
            InputExpectedType::Contact  => [ChannelCapability::ShareContact],
            InputExpectedType::Location => [ChannelCapability::ShareLocation],
            // Select and confirm prompts carry an inline keyboard; the input handler never removes it afterwards.
            InputExpectedType::Select, InputExpectedType::Confirm => [ChannelCapability::CallbackButtons],
            default                                               => [],
        };
    }

    private function describe(ChannelCapability $capability): string
    {
        return match ($capability) {
            ChannelCapability::CallbackButtons   => 'show buttons under a message that send a press back to the flow',
            ChannelCapability::ReplyButtons      => 'show reply buttons',
            ChannelCapability::ShareContact      => 'ask the user to share a contact',
            ChannelCapability::ShareLocation     => 'ask the user to share a location',
            ChannelCapability::RemoveButtons     => 'remove buttons from a sent message: they stay until the next message',
            ChannelCapability::PersistentButtons => 'keep the buttons of older messages pressable',
        };
    }
}
