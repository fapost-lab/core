<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\InputExpectedType;
use App\Domains\Flow\Enums\SendMessageContentType;
use Fapost\Foundation\Messaging\ButtonLimits;

/**
 * Finds keyboards that are larger than a channel of the assistant declares it can show.
 *
 * Counts what the node's configuration fixes in advance, mirroring how the send_message handler, the input handler
 * and FlowMessageSender build the keyboard: buttons are grouped into rows by their `row` field (default 0). Dynamic
 * buttons are built from a variable at runtime, so only their `max_per_row` is known. A send_message label that
 * contains a `{{placeholder}}` is skipped: its length is known only after rendering. A limit a channel leaves
 * undeclared is not checked. Every channel of the assistant counts, active or not.
 *
 * The result is advice, never an error: one warning names a node, a kind of limit and every channel type that
 * exceeds it. The registry is a singleton without tenant data, so the checker keeps no state between calls.
 */
final readonly class ButtonLimitChecker
{
    public const string CODE = 'channel_button_limit_exceeded';

    private const int DEFAULT_DYNAMIC_ROW_SIZE = 2;

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
            $keyboard = $this->keyboard($node);

            if (null === $keyboard) {
                continue;
            }

            $messages = array_filter([
                $this->exceeded(
                    $channelTypes,
                    $keyboard['total'],
                    static fn (ButtonLimits $limits): ?int => $limits->maxButtons,
                    'buttons in one message',
                    static fn (int $actual): string => "this node has {$actual}",
                ),
                $this->exceeded(
                    $channelTypes,
                    $keyboard['row'],
                    static fn (ButtonLimits $limits): ?int => $limits->maxButtonsPerRow,
                    'buttons in a row',
                    static fn (int $actual): string => $keyboard['dynamic']
                        ? "this node puts up to {$actual} dynamic buttons in a row"
                        : "this node has a row of {$actual}",
                ),
                $this->exceeded(
                    $channelTypes,
                    $keyboard['label'],
                    static fn (ButtonLimits $limits): ?int => $limits->maxLabelLength,
                    'characters in a button label',
                    static fn (int $actual): string => "the longest label here has {$actual}",
                ),
            ]);

            foreach ($messages as $message) {
                $warnings[] = new FlowValidationErrorDto(
                    path: "nodes.{$nodeId}",
                    code: self::CODE,
                    message: $message,
                );
            }
        }

        return $warnings;
    }

    /**
     * Builds "Telegram shows at most 8 buttons in a row; this node has a row of 10." when any channel type is
     * exceeded. Channel types with different limits are named per limit, strictest first.
     *
     * @param  list<string>                                    $channelTypes
     * @param  callable(ButtonLimits): ?int  $limitOf
     * @param  callable(int): string                           $describeActual
     */
    private function exceeded(array $channelTypes, int $actual, callable $limitOf, string $unit, callable $describeActual): ?string
    {
        /** @var array<int, list<string>> $byLimit */
        $byLimit = [];

        foreach ($channelTypes as $type) {
            $limit = $limitOf($this->channelRegistry->capabilities($type)->buttonLimits);

            if (null !== $limit && $actual > $limit) {
                $byLimit[$limit][] = $this->channelRegistry->label($type);
            }
        }

        if ([] === $byLimit) {
            return null;
        }

        ksort($byLimit);

        $parts = [];

        foreach ($byLimit as $limit => $labels) {
            $parts[] = sprintf('%s %s at most %d %s', implode(', ', $labels), 1 === count($labels) ? 'shows' : 'show', $limit, $unit);
        }

        return implode('; ', $parts) . '; ' . $describeActual($actual) . '.';
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return array{total: int, row: int, label: int, dynamic: bool}|null
     */
    private function keyboard(array $node): ?array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return match ($node['type'] ?? null) {
            'send_message' => $this->sendMessageKeyboard($config),
            'input'        => $this->inputKeyboard($config),
            default        => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array{total: int, row: int, label: int, dynamic: bool}|null
     */
    private function sendMessageKeyboard(array $config): ?array
    {
        if (SendMessageContentType::TextWithKeyboard->value !== ($config['content_type'] ?? null)) {
            return null;
        }

        // Dynamic buttons replace the static ones at runtime, and only the row size is known in advance.
        if (is_array($config['dynamic_buttons'] ?? null)) {
            $perRow = $config['dynamic_buttons']['max_per_row'] ?? null;

            return [
                'total'   => 0,
                'row'     => is_int($perRow) ? max(1, $perRow) : self::DEFAULT_DYNAMIC_ROW_SIZE,
                'label'   => 0,
                'dynamic' => true,
            ];
        }

        return $this->staticKeyboard($config, skipTemplatedLabels: true);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array{total: int, row: int, label: int, dynamic: bool}|null
     */
    private function inputKeyboard(array $config): ?array
    {
        $type = is_string($config['expected_type'] ?? null) ? InputExpectedType::tryFrom($config['expected_type']) : null;

        if (! in_array($type, [InputExpectedType::Select, InputExpectedType::Confirm], true)) {
            return null;
        }

        // The input handler resolves labels by language only; it never renders templates in them.
        return $this->staticKeyboard($config, skipTemplatedLabels: false);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array{total: int, row: int, label: int, dynamic: bool}|null
     */
    private function staticKeyboard(array $config, bool $skipTemplatedLabels): ?array
    {
        $buttons = is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [];

        if ([] === $buttons) {
            return null;
        }

        $rows     = [];
        $maxLabel = 0;

        foreach ($buttons as $button) {
            $button = is_array($button) ? $button : [];
            $row    = (int) ($button['row'] ?? 0);

            $rows[$row] = ($rows[$row] ?? 0) + 1;

            foreach ($this->labels($button['label'] ?? null) as $label) {
                if ($skipTemplatedLabels && str_contains($label, '{{')) {
                    continue;
                }

                $maxLabel = max($maxLabel, mb_strlen($label));
            }
        }

        return [
            'total'   => count($buttons),
            'row'     => max($rows),
            'label'   => $maxLabel,
            'dynamic' => false,
        ];
    }

    /**
     * A label is a string or a map of language => string.
     *
     * @return list<string>
     */
    private function labels(mixed $label): array
    {
        if (is_string($label)) {
            return [$label];
        }

        if (! is_array($label)) {
            return [];
        }

        return array_values(array_filter($label, is_string(...)));
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
}
