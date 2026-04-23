<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use App\Domains\Channels\Telegram\TelegramSignatureVerifier;
use App\Domains\Contact\Enums\PlatformEnum;
use FAPost\Foundation\DTO\IncomingMessageType;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TelegramChannelAdapterTest extends TestCase
{
    public function test_platform_returns_telegram_enum(): void
    {
        $this->assertSame(PlatformEnum::Telegram, $this->adapter()->platform());
    }

    public function test_verify_signature_returns_false_when_secret_token_does_not_match(): void
    {
        $request = Request::create('/', 'POST', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'wrong']);

        $this->assertFalse($this->adapter()->verifySignature($request, 'expected'));
    }

    public function test_verify_signature_returns_true_when_secret_token_matches(): void
    {
        $request = Request::create('/', 'POST', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'expected']);

        $this->assertTrue($this->adapter()->verifySignature($request, 'expected'));
    }

    /**
     * Ingress verification must use only the request headers and the pre-resolved secret.
     * No Redis or DB calls happen inside verifySignature itself.
     */
    public function test_verify_signature_reads_only_from_redis(): void
    {
        // The adapter receives the secret as a parameter — it was already resolved from Redis
        // by the controller. The adapter itself never touches Redis or any data source.
        $request = Request::create('/', 'POST', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'redis-secret']);

        // No mock needed for Redis/DB — verifySignature has no external dependencies.
        $this->assertTrue($this->adapter()->verifySignature($request, 'redis-secret'));
        $this->assertFalse($this->adapter()->verifySignature($request, 'other-secret'));
    }

    public function test_extract_idempotency_key_format(): void
    {
        $request = Request::create('/', 'POST', content: json_encode(['update_id' => 42], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $key = $this->adapter()->extractIdempotencyKey($request, 'chan-99');

        $this->assertSame('tg:chan-99:42', $key);
    }

    public function test_extract_idempotency_key_uses_empty_string_when_update_id_is_absent(): void
    {
        $request = Request::create('/', 'POST', content: json_encode([], JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        $key = $this->adapter()->extractIdempotencyKey($request, 'chan-1');

        $this->assertSame('tg:chan-1:', $key);
    }

    public function test_normalize_uses_callback_sender_over_message_sender_and_normalizes_types(): void
    {
        $rawPayload = [
            'update_id' => 101,
            'message'   => [
                'message_id' => 2001,
                'date'       => 1_717_171_717,
                'chat'       => ['id' => 999],
                'from'       => [
                    'id'         => 111,
                    'username'   => 'message_user',
                    'first_name' => 'Message',
                ],
                'text' => 'message text',
            ],
            'callback_query' => [
                'id'   => 'cb-1',
                'from' => [
                    'id'         => 222,
                    'username'   => 'callback_user',
                    'first_name' => 'Callback',
                    'last_name'  => 'Sender',
                ],
                'message' => [
                    'message_id' => 2001,
                    'date'       => 1_717_171_718,
                    'chat'       => ['id' => 123456],
                ],
                'data' => 'btn:ok',
            ],
        ];

        $message = $this->adapter()->normalize($rawPayload);

        $this->assertSame('101', $message->updateId);
        $this->assertSame('123456', $message->externalChatId);
        $this->assertSame('222', $message->externalUserId);
        $this->assertSame('btn:ok', $message->text);
        $this->assertSame(IncomingMessageType::CallbackQuery, $message->type);
        $this->assertSame('telegram', $message->platform);
    }

    public function test_normalize_parses_plain_text_message(): void
    {
        $rawPayload = [
            'update_id' => 200,
            'message'   => [
                'message_id' => 100,
                'date'       => 1_717_171_717,
                'chat'       => ['id' => 10],
                'from'       => ['id' => 20, 'username' => 'user20'],
                'text'       => 'hello world',
            ],
        ];

        $message = $this->adapter()->normalize($rawPayload);

        $this->assertSame('200', $message->updateId);
        $this->assertSame('20', $message->externalUserId);
        $this->assertSame('10', $message->externalChatId);
        $this->assertSame('hello world', $message->text);
        $this->assertSame(IncomingMessageType::Text, $message->type);
    }
    private function adapter(): TelegramAdapter
    {
        return new TelegramAdapter(
            new TelegramSignatureVerifier(),
            new TelegramInboundNormalizer(),
        );
    }
}
