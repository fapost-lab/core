<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use Tests\TestCase;

final class WebhookControllerTest extends TestCase
{
    public function test_it_returns_ok_when_registry_is_missing(): void
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

    public function test_it_verifies_signature_before_idempotency_mark_and_returns_ok_on_invalid_signature(): void
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

    public function test_it_dispatches_incoming_message_job_with_channel_scoped_idempotency_key(): void
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
            ->with('processed:channel-777:987654', '1', 'EX', 86400, 'NX')
            ->andReturn(true);

        $response = $this->postJson(
            '/webhook/telegram/ok-hash',
            [
                'update_id' => 987654,
                'message'   => [
                    'date' => 1_717_171_717,
                    'chat' => ['id' => 456],
                    'from' => ['id' => 123],
                    'text' => 'hello',
                ],
            ],
            ['X-Telegram-Bot-Api-Secret-Token' => 'expected-secret'],
        );

        $response
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertDispatched(
            IncomingMessageJob::class,
            static fn (IncomingMessageJob $job): bool => 'tenant-1' === $job->tenantId
                && 'assistant-9' === $job->assistantId
                && 'channel-777' === $job->channelId
                && 'main' === $job->schema
                && '987654' === $job->message->updateId
                && 'flow.execution' === $job->queue,
        );
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
            platform: PlatformEnum::Telegram,
            secretToken: $secretToken,
        );
    }
}
