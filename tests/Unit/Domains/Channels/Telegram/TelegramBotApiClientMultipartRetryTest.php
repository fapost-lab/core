<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use App\Domains\Channels\Telegram\TelegramBotApiClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * A multipart upload that fails on the connection is retried with the whole file, not with a stream
 * the first attempt already consumed and closed.
 */
final class TelegramBotApiClientMultipartRetryTest extends TestCase
{
    /** @var list<string> */
    private array $sentBodies = [];

    public function test_retry_after_connection_error_sends_the_whole_file(): void
    {
        $this->fakeTransport([
            new ConnectException('cURL error 7: connection refused', new Request('POST', 'https://api.telegram.org')),
            new Response(200, [], (string)json_encode(['ok' => true, 'result' => ['message_id' => 1]])),
        ]);

        $response = $this->client()->sendDocumentMultipart('42', $this->stream('FILE-BYTES'), 'a.txt');

        $this->assertTrue($response['ok']);
        $this->assertCount(2, $this->sentBodies);
        $this->assertStringContainsString('FILE-BYTES', $this->sentBodies[0]);
        $this->assertStringContainsString('FILE-BYTES', $this->sentBodies[1]);
    }

    public function test_unseekable_stream_is_not_retried(): void
    {
        $this->fakeTransport([
            new ConnectException('cURL error 7: connection refused', new Request('POST', 'https://api.telegram.org')),
            new Response(200, [], (string)json_encode(['ok' => true])),
        ]);

        $pipe = popen('printf FILE-BYTES', 'r');

        try {
            $this->client()->sendDocumentMultipart('42', $pipe, 'a.txt');
            $this->fail('Expected a TelegramApiException.');
        } catch (TelegramApiException $exception) {
            $this->assertStringContainsString('cURL error 7', $exception->getMessage());
        }

        $this->assertCount(1, $this->sentBodies);
    }

    /**
     * @param  list<mixed>  $queue
     */
    private function fakeTransport(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->sentBodies[] = (string)$request->getBody();
                $request->getBody()->close();

                return $next($request, $options);
            };
        });

        Http::globalOptions(['handler' => $stack]);
    }

    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function client(): TelegramBotApiClient
    {
        return new TelegramBotApiClient('123456789:AAHsecret-Token_value');
    }
}
