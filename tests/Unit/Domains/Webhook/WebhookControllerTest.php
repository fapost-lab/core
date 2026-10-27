<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSignedInboundAdapter;
use Tests\TestCase;

final class WebhookControllerTest extends TestCase
{
    public function test_returns_ok_when_registry_is_missing(): void
    {
        Bus::fake();

        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('missing-hash')
                ->andThrow(new WebhookRegistryException('not found'));
        });

        $response = $this->postJson('/webhook/telegram/missing-hash', ['update_id' => 123]);

        $response
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertNothingDispatched();
    }

    public function test_returns_401_on_invalid_signature(): void
    {
        Bus::fake();

        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('test-hash')
                ->andReturn($this->makeEntry('channel-1', 'expected-secret'));
        });

        Redis::shouldReceive('set')->never();

        $response = $this->postJson(
            '/webhook/telegram/test-hash',
            ['update_id'                       => 555],
            ['X-Telegram-Bot-Api-Secret-Token' => 'forged-secret'],
        );

        $response
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertNothingDispatched();
    }

    public function test_an_empty_secret_never_verifies(): void
    {
        Bus::fake();

        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->with('test-hash')->andReturn($this->makeEntry('channel-1', ''));
        });

        Redis::shouldReceive('set')->never();

        $this->postJson('/webhook/telegram/test-hash', ['update_id' => 555])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertNothingDispatched();
    }

    public function test_returns_200_on_duplicate_update_id(): void
    {
        Bus::fake();

        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('ok-hash')
                ->andReturn($this->makeEntry('channel-1', 'expected-secret'));
        });

        Redis::shouldReceive('set')
            ->once()
            ->with('processed:tg:channel-1:999', '1', 'EX', 86400, 'NX')
            ->andReturn(false); // already processed

        $response = $this->postJson(
            '/webhook/telegram/ok-hash',
            ['update_id'                       => 999, 'message' => ['chat' => ['id' => 1], 'from' => ['id' => 1], 'date' => 0]],
            ['X-Telegram-Bot-Api-Secret-Token' => 'expected-secret'],
        );

        $response
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertNothingDispatched();
    }

    public function test_dispatches_job_with_raw_payload(): void
    {
        Bus::fake();

        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('ok-hash')
                ->andReturn($this->makeEntry('channel-777', 'expected-secret', 'tenant-1', 'assistant-9', 'main'));
        });

        Redis::shouldReceive('set')
            ->once()
            ->with('processed:tg:channel-777:987654', '1', 'EX', 86400, 'NX')
            ->andReturn(true);

        $rawBody = [
            'update_id' => 987654,
            'message'   => [
                'date' => 1_717_171_717,
                'chat' => ['id' => 456],
                'from' => ['id' => 123],
                'text' => 'hello',
            ],
        ];

        $response = $this->postJson(
            '/webhook/telegram/ok-hash',
            $rawBody,
            ['X-Telegram-Bot-Api-Secret-Token' => 'expected-secret'],
        );

        $response
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 'tenant-1' === $job->payload->tenantId
                && 'assistant-9' === $job->payload->assistantId
                && 'channel-777' === $job->payload->channelId
                && 'main' === $job->payload->schema
                && 'telegram' === $job->payload->platform
                && 'tg:channel-777:987654' === $job->payload->idempotencyKey
                && 987654 === $job->payload->rawPayload['update_id']
                && 'flow.execution' === $job->queue,
        );
    }

    public function test_a_connector_adapter_is_verified_and_deduplicated_through_its_ingress_spec(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoInboundAdapter(), $this->makeEntry('channel-5', 'echo-secret'));

        Redis::shouldReceive('set')
            ->once()
            ->with('processed:echo:channel-5:42', '1', 'EX', 86400, 'NX')
            ->andReturn(true);

        $this->postJson('/webhook/telegram/ok-hash', ['id' => 42, 'text' => 'hi'], ['X-Echo-Secret' => 'echo-secret'])
            ->assertOk();

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 'echo:channel-5:42' === $job->payload->idempotencyKey
                && 42 === $job->payload->rawPayload['id'],
        );
    }

    public function test_a_connector_adapter_rejects_a_forged_secret(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoInboundAdapter(), $this->makeEntry('channel-5', 'echo-secret'));

        Redis::shouldReceive('set')->never();

        $this->postJson('/webhook/telegram/ok-hash', ['id' => 42], ['X-Echo-Secret' => 'forged'])->assertOk();

        Bus::assertNothingDispatched();
    }

    public function test_a_connector_adapter_never_verifies_against_an_empty_secret(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoInboundAdapter(), $this->makeEntry('channel-5', ''));

        Redis::shouldReceive('set')->never();

        $this->postJson('/webhook/telegram/ok-hash', ['id' => 42])->assertOk();

        Bus::assertNothingDispatched();
    }

    public function test_a_verified_handshake_is_answered_in_plain_text_without_deduplication_or_a_job(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoSignedInboundAdapter(), $this->makeEntry('channel-5', 'echo-secret'));

        Redis::shouldReceive('set')->never();

        $body     = '{"type":"url_verification","challenge":"abc 123"}';
        $response = $this->call('POST', '/webhook/telegram/ok-hash', [], [], [], $this->server($this->signatureHeaders($body, 'echo-secret')), $body);

        $response->assertOk();
        $this->assertSame('abc 123', $response->getContent());
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));

        Bus::assertNothingDispatched();
    }

    public function test_an_unsigned_handshake_is_not_echoed(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoSignedInboundAdapter(), $this->makeEntry('channel-5', 'echo-secret'));

        Redis::shouldReceive('set')->never();

        $body     = '{"type":"url_verification","challenge":"abc 123"}';
        $response = $this->call('POST', '/webhook/telegram/ok-hash', [], [], [], $this->server($this->signatureHeaders($body, 'wrong-secret')), $body);

        $response->assertOk()->assertExactJson(['ok' => true]);

        Bus::assertNothingDispatched();
    }

    public function test_a_form_body_is_queued_as_the_json_document_of_its_field(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoSignedInboundAdapter(), $this->makeEntry('channel-5', 'echo-secret'));

        Redis::shouldReceive('set')
            ->once()
            ->with('processed:echo:channel-5:trig-1', '1', 'EX', 86400, 'NX')
            ->andReturn(true);

        $body = 'token=x&payload=' . rawurlencode('{"trigger_id":"trig-1","from":"u1","text":"a b"}');

        $this->call('POST', '/webhook/telegram/ok-hash', [], [], [], $this->server($this->signatureHeaders($body, 'echo-secret'), 'application/x-www-form-urlencoded'), $body)
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 'echo:channel-5:trig-1' === $job->payload->idempotencyKey
                && ['trigger_id' => 'trig-1', 'from' => 'u1', 'text' => 'a b'] === $job->payload->rawPayload,
        );
    }

    public function test_a_stale_timestamp_is_answered_ok_and_queues_nothing(): void
    {
        Bus::fake();
        $this->registryReturns(new EchoSignedInboundAdapter(), $this->makeEntry('channel-5', 'echo-secret'));

        Redis::shouldReceive('set')->never();

        $body = '{"event_id":"e1","from":"u1"}';

        $this->call('POST', '/webhook/telegram/ok-hash', [], [], [], $this->server($this->signatureHeaders($body, 'echo-secret', time() - 301)), $body)
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Bus::assertNothingDispatched();
    }

    public function test_returns_200_without_db_access(): void
    {
        Bus::fake();

        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn($this->makeEntry('channel-1', 'secret'));
        });

        Redis::shouldReceive('set')
            ->once()
            ->andReturn(true);

        DB::shouldReceive('select')->never();
        DB::shouldReceive('statement')->never();

        $this->postJson(
            '/webhook/telegram/any-hash',
            ['update_id'                       => 1, 'message' => ['chat' => ['id' => 1], 'from' => ['id' => 1], 'date' => 0, 'text' => 'hi']],
            ['X-Telegram-Bot-Api-Secret-Token' => 'secret'],
        )->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function signatureHeaders(string $body, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'x-echo-timestamp' => (string) $timestamp,
            'x-echo-signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @return array<string, string>
     */
    private function server(array $headers, string $contentType = 'application/json'): array
    {
        $server = ['CONTENT_TYPE' => $contentType];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . mb_strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function registryReturns(object $adapter, WebhookRegistryEntry $entry): void
    {
        $this->mock(WebhookRegistryResolverInterface::class, function (MockInterface $mock) use ($entry): void {
            $mock->shouldReceive('resolve')->once()->with('ok-hash')->andReturn($entry);
        });

        $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($adapter): void {
            $mock->shouldReceive('adapter')->with('telegram')->andReturn($adapter);
        });
    }

    private function makeEntry(
        string $channelId = 'channel-1',
        string $secretToken = 'secret',
        string $tenantId = 'tenant-1',
        string $assistantId = 'assistant-1',
        string $schema = 'main',
    ): WebhookRegistryEntry {
        return new WebhookRegistryEntry(
            tenantId: $tenantId,
            assistantId: $assistantId,
            channelId: $channelId,
            schema: $schema,
            platform: PlatformEnum::Telegram->value,
            secretToken: $secretToken,
        );
    }
}
