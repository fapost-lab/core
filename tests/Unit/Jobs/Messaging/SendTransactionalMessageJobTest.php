<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Messaging;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Messaging\SendTransactionalMessageJob;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SendTransactionalMessageJobTest extends TestCase
{
    public function test_duplicate_result_returns_without_exception(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: false, duplicate: true));
        });

        $job = new SendTransactionalMessageJob($this->message());
        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher());

        $this->assertTrue(true);
    }

    public function test_unsent_non_duplicate_result_throws_to_trigger_retry(): void
    {
        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new DeliveryResult(sent: false, error: 'provider failed'));
        });

        $job = new SendTransactionalMessageJob($this->message());

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

        $job = new SendTransactionalMessageJob($this->message());
        $job->handle($sender, $this->tenantRepository(), $this->tenantSwitcher($tenantContext));
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: 'idem-1',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
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
            $mock->shouldReceive('forgetCachedPermissions')->twice();
        });

        return new TenantSwitcher($tenantContext, $databaseManager, $permissionRegistrar);
    }
}
