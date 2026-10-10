<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Messaging;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Messaging\BroadcastSendJob;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

final class BroadcastSendJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_successful_delivery_completes_without_exception(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: true, providerMessageId: '77'));
        });

        $job = new BroadcastSendJob($this->message($this->channel()));

        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());

        $this->addToAssertionCount(1);
    }

    public function test_the_queued_job_carries_no_token_and_the_sender_gets_it_from_the_channel(): void
    {
        $channel = $this->channel();
        $job     = new BroadcastSendJob($this->message($channel));

        $this->assertNull($job->message->transportToken);
        $this->assertStringNotContainsString('bot-token', serialize($job));

        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->withArgs(
                fn (OutboundMessage $message): bool => 'bot-token' === $message->transportToken
                    && 'idem-1' === $message->idempotencyKey
                    && 'chat-1' === $message->chatId
                    && 'Hello' === $message->payload->text,
            )->andReturn(new DeliveryResult(sent: true));
        });

        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());
    }

    public function test_nothing_is_sent_when_the_channel_is_gone(): void
    {
        $channel = $this->channel();
        $job     = new BroadcastSendJob($this->message($channel));

        Channel::withoutEvents(static fn (): ?bool => $channel->forceDelete());

        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->never();
        });

        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());

        $this->addToAssertionCount(1);
    }

    public function test_sender_exception_bubbles_for_retry(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('provider unavailable'));
        });

        $job = new BroadcastSendJob($this->message($this->channel()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider unavailable');

        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());
    }

    public function test_a_refused_outbound_volume_ends_the_job_quietly_without_a_retry(): void
    {
        Log::spy();

        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new VolumeLimitReachedException('outbound_messages', 10, 10));
        });

        $job = new BroadcastSendJob($this->message($this->channel()));

        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());

        Log::shouldHaveReceived('info')->once()->with(
            'Broadcast message dropped: outbound message limit reached.',
            ['tenant_id' => 'tenant-1', 'key' => 'outbound_messages', 'limit' => 10, 'used' => 10],
        );
    }

    public function test_unsent_non_duplicate_result_throws_for_retry(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: false, error: 'provider failed'));
        });

        $job = new BroadcastSendJob($this->message($this->channel()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider failed');

        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());
    }

    public function test_delivery_runs_inside_tenant_context(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: true));
        });

        $tenantContext = $this->mock(TenantContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isResolved')->once()->andReturn(false);
            $mock->shouldReceive('set')->once()->withArgs(
                fn (RuntimeTenant $tenant): bool => 'tenant-1' === $tenant->getId(),
            );
            $mock->shouldReceive('reset')->once();
        });

        $job = new BroadcastSendJob($this->message($this->channel()));
        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher($tenantContext));
    }

    private function channel(): Channel
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->id,
            'tenant_id'    => self::TENANT_ID,
            'token'        => 'bot-token',
        ]));
    }

    private function message(Channel $channel): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: 'idem-1',
            tenantId: 'tenant-1',
            channelId: (string) $channel->getKey(),
            channelType: 'telegram',
            transportToken: 'bot-token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello'),
        );
    }

    private function tenantRepository(): TenantRepositoryInterface
    {
        return $this->mock(TenantRepositoryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getById')->once()->with('tenant-1')
                ->andReturn(new RuntimeTenant(id: 'tenant-1', schemaName: 'tenant_test'));
        });
    }

    private function tenantSwitcher(?TenantContextInterface $tenantContext = null): TenantSwitcher
    {
        $tenantContext ??= $this->mock(TenantContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isResolved')->once()->andReturn(false);
            $mock->shouldReceive('set')->once();
            $mock->shouldReceive('reset')->once();
        });

        $databaseManager = $this->mock(TenantDatabaseManagerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('switchTo')->once();
            $mock->shouldReceive('restore')->once();
        });

        $permissionRegistrar = $this->mock(PermissionRegistrar::class, function (MockInterface $mock): void {
            $mock->shouldReceive('clearPermissionsCollection')->twice();
        });

        return new TenantSwitcher($tenantContext, $databaseManager, $permissionRegistrar);
    }
}
