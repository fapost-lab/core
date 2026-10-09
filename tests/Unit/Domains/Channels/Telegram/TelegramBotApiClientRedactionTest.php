<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendMessageDto;
use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use App\Domains\Channels\Telegram\TelegramBotApiClient;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * The bot token is part of every request URL, so it must not leave the client in an exception:
 * neither in the message nor in anything chained behind it (logs, Sentry and `failed_jobs` keep both).
 */
final class TelegramBotApiClientRedactionTest extends TestCase
{
    private const string TOKEN = '123456789:AAHsecret-Token_value';

    public function test_connection_error_carries_no_token(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException(
                'cURL error 6: Could not resolve host: api.telegram.org for https://api.telegram.org/bot'
                . self::TOKEN . '/getMe',
            );
        });

        $exception = $this->failureOf(fn () => $this->client()->getMe());

        $this->assertStringContainsString('cURL error 6', $exception->getMessage());
        $this->assertStringContainsString('/bot***/getMe', $exception->getMessage());
        $this->assertChainHasNoToken($exception);
    }

    public function test_client_error_response_carries_no_token(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $exception = $this->failureOf(fn () => $this->client()->getMe());

        $this->assertStringContainsString('401', $exception->getMessage());
        $this->assertChainHasNoToken($exception);
    }

    public function test_server_error_response_carries_no_token(): void
    {
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $exception = $this->failureOf(
            fn () => $this->client()->sendMessage(new SendMessageDto(chatId: '1', text: 'hi')),
        );

        $this->assertStringContainsString('502', $exception->getMessage());
        $this->assertChainHasNoToken($exception);
    }

    public function test_transport_error_on_multipart_upload_carries_no_token(): void
    {
        // Not a ConnectionException: that one is retried, and the upload stream is consumed by the first attempt.
        Http::fake(static function (): never {
            throw new RuntimeException('transfer failed for https://api.telegram.org/bot' . self::TOKEN . '/sendPhoto');
        });

        $stream = fopen('php://memory', 'r+');

        $exception = $this->failureOf(fn () => $this->client()->sendPhotoMultipart('1', $stream, 'a.jpg'));

        $this->assertStringContainsString('/bot***/sendPhoto', $exception->getMessage());
        $this->assertChainHasNoToken($exception);
    }

    public function test_connection_error_on_file_download_carries_no_token(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 7: refused for https://api.telegram.org/file/bot' . self::TOKEN . '/photos/1.jpg');
        });

        $exception = $this->failureOf(fn () => $this->client()->downloadFile('photos/1.jpg'));

        $this->assertStringContainsString('/file/bot***/photos/1.jpg', $exception->getMessage());
        $this->assertChainHasNoToken($exception);
    }

    public function test_description_echoing_the_token_is_masked(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'bad token ' . self::TOKEN])]);

        $exception = $this->failureOf(fn () => $this->client()->getMe());

        $this->assertSame('bad token ***', $exception->getMessage());
    }

    public function test_the_original_exception_code_is_kept(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('boom', 28);
        });

        $this->assertSame(28, $this->failureOf(fn () => $this->client()->getMe())->getCode());
    }

    private function client(): TelegramBotApiClient
    {
        return new TelegramBotApiClient(self::TOKEN);
    }

    private function failureOf(Closure $call): TelegramApiException
    {
        try {
            $call();
        } catch (TelegramApiException $exception) {
            return $exception;
        }

        $this->fail('Expected a TelegramApiException.');
    }

    private function assertChainHasNoToken(Throwable $exception): void
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            $this->assertStringNotContainsString(self::TOKEN, $current->getMessage());
            $this->assertStringNotContainsString(rawurlencode(self::TOKEN), $current->getMessage());
        }
    }
}
