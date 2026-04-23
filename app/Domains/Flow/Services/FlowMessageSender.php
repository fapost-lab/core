<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Models\FlowSession;
use FAPost\Foundation\Flow\Enums\KeyboardMode;
use FAPost\Foundation\Messaging\MessagePayload;
use FAPost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use JsonException;
use RuntimeException;

final readonly class FlowMessageSender implements MessageSenderInterface
{
    public function __construct(
        private OutboundMessageSenderInterface $sender,
    ) {
    }

    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
    {
        $session = FlowSession::query()
            ->select(['id', 'assistant_id'])
            ->find($sessionId);

        if ( ! $session instanceof FlowSession) {
            throw new RuntimeException("Flow session '{$sessionId}' was not found.");
        }

        $channelContact = ChannelContact::query()
            ->select('channel_contacts.*')
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channel_contacts.contact_id', $contactId)
            ->where('channels.assistant_id', $session->assistant_id)
            ->where('channels.is_active', true)
            ->with(['channel', 'contact'])
            ->orderByDesc('channel_contacts.last_interaction_at')
            ->first();

        if (
            ! $channelContact instanceof ChannelContact
            || ! $channelContact->channel instanceof Channel
            || null === $channelContact->contact
        ) {
            throw new RuntimeException("No active delivery channel found for contact '{$contactId}'.");
        }

        $channel = $channelContact->channel;
        $message = new OutboundMessage(
            idempotencyKey: "{$sessionId}:{$payload['node_id']}:{$payload['idempotency_key']}",
            tenantId: $tenantId,
            channelId: (string) $channel->getKey(),
            channelType: $channel->type->value,
            transportToken: $channel->token,
            chatId: (string) $channelContact->contact->external_id,
            payload: $this->toMessagePayload($payload),
            metadata: [
                'flow_session_id' => $sessionId,
                'parse_mode'      => 'HTML',
            ],
        );

        $result = $this->sender->send($message);

        if ( ! $result->sent) {
            throw new RuntimeException($result->error ?? 'Failed to send outbound flow message.');
        }

        return $result->providerMessageId ?? 'default';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toMessagePayload(array $payload): MessagePayload
    {
        $contentType = SendMessageContentType::from((string) $payload['content_type']);

        return match ($contentType) {
            SendMessageContentType::Text => new MessagePayload(
                type: 'text',
                text: (string) ($payload['text'] ?? ''),
            ),
            SendMessageContentType::TextWithKeyboard => new MessagePayload(
                type: 'keyboard',
                text: (string) ($payload['text'] ?? ''),
                keyboard: $this->buildKeyboard(
                    buttons: is_array($payload['buttons'] ?? null) ? $payload['buttons'] : [],
                    keyboardMode: KeyboardMode::from((string) $payload['keyboard_mode']),
                    sessionId: (string) $payload['session_id'],
                ),
            ),
            SendMessageContentType::Image => new MessagePayload(
                type: 'photo',
                text: (string) ($payload['caption'] ?? ''),
                media: ['photo' => (string) $payload['media_url']],
            ),
            SendMessageContentType::Document => new MessagePayload(
                type: 'document',
                text: (string) ($payload['caption'] ?? ''),
                media: ['document' => (string) $payload['media_url']],
            ),
            SendMessageContentType::Video => new MessagePayload(
                type: 'video',
                text: (string) ($payload['caption'] ?? ''),
                media: ['video' => (string) $payload['media_url']],
            ),
            SendMessageContentType::Voice => new MessagePayload(
                type: 'voice',
                text: '',
                media: ['voice' => (string) $payload['media_url']],
            ),
        };
    }

    /**
     * @param list<array<string, mixed>> $buttons
     * @return array<string, mixed>
     */
    private function buildKeyboard(array $buttons, KeyboardMode $keyboardMode, string $sessionId): array
    {
        $rows = [];

        usort($buttons, static function (array $left, array $right): int {
            $leftRow  = (int) ($left['row'] ?? 0);
            $rightRow = (int) ($right['row'] ?? 0);

            if ($leftRow !== $rightRow) {
                return $leftRow <=> $rightRow;
            }

            return ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
        });

        foreach ($buttons as $button) {
            $rowIndex = (int) ($button['row'] ?? 0);
            $rows[$rowIndex] ??= [];

            $rows[$rowIndex][] = match ($keyboardMode) {
                KeyboardMode::Inline => [
                    'text'          => (string) ($button['label'] ?? ''),
                    'callback_data' => $this->encodeCallbackData($sessionId, (string) ($button['id'] ?? '')),
                ],
                KeyboardMode::Reply => [
                    'text' => (string) ($button['label'] ?? ''),
                ],
            };
        }

        ksort($rows);
        $normalizedRows = array_values($rows);

        return match ($keyboardMode) {
            KeyboardMode::Inline => ['inline_keyboard' => $normalizedRows],
            KeyboardMode::Reply  => [
                'keyboard'          => $normalizedRows,
                'one_time_keyboard' => true,
                'resize_keyboard'   => true,
            ],
        };
    }

    private function encodeCallbackData(string $sessionId, string $buttonId): string
    {
        try {
            return json_encode([
                'session_id' => $sessionId,
                'button_id'  => $buttonId,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode callback payload.', previous: $exception);
        }
    }
}
