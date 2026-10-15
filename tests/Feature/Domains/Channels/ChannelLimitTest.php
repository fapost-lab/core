<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Concerns\SetsRecordLimits;
use Tests\Feature\FeatureTestCase;

/**
 * The channel limit counts every channel of the tenant, inactive ones included, and is checked in
 * ChannelService::create().
 */
final class ChannelLimitTest extends FeatureTestCase
{
    use SetsRecordLimits;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncChannelWebhookJob::class]);
        $this->seed(TenantAclSeeder::class);
    }

    public function test_service_creates_until_the_limit_then_throws(): void
    {
        $this->limitRecords('channels', 1);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $service = app(ChannelServiceInterface::class);

            $service->create($assistant, $this->payload());

            try {
                $service->create($assistant, $this->payload());
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException $e) {
                $this->assertSame('Channels limit reached: 1 of 1.', $e->getMessage());
            }

            $this->assertSame(1, Channel::query()->count());
        });
    }

    public function test_default_has_no_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new UnlimitedTenantLimits());
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $service = app(ChannelServiceInterface::class);

            $service->create($assistant, $this->payload());
            $service->create($assistant, $this->payload());

            $this->assertSame(2, Channel::query()->count());
        });
    }

    public function test_limit_zero_refuses_the_first_channel(): void
    {
        $this->limitRecords('channels', 0);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $this->expectException(RecordLimitReachedException::class);

            app(ChannelServiceInterface::class)->create($assistant, $this->payload());
        });
    }

    public function test_inactive_channels_count_and_deleting_one_frees_its_place(): void
    {
        $this->limitRecords('channels', 1);
        $assistant = Assistant::factory()->create();

        $this->inTenant(function () use ($assistant): void {
            $service = app(ChannelServiceInterface::class);
            $channel = $service->create($assistant, ['is_active' => false] + $this->payload());

            try {
                $service->create($assistant, $this->payload());
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                // an inactive channel still holds its place
            }

            $channel->delete();

            $service->create($assistant, $this->payload());
            $this->assertSame(1, Channel::query()->count());
        });
    }

    public function test_downgrade_keeps_existing_channels_but_forbids_new_ones(): void
    {
        $assistant = Assistant::factory()->create();
        Channel::withoutEvents(fn () => Channel::factory()->count(2)->create(['assistant_id' => $assistant->getKey()]));
        $this->limitRecords('channels', 1);

        $this->inTenant(function () use ($assistant): void {
            try {
                app(ChannelServiceInterface::class)->create($assistant, $this->payload());
                $this->fail('Expected RecordLimitReachedException.');
            } catch (RecordLimitReachedException) {
                $this->assertSame(2, Channel::query()->count());
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'type'         => ChannelTypeEnum::Telegram->value,
            'token'        => 'token-' . bin2hex(random_bytes(4)),
            'secret_token' => 'secret-' . bin2hex(random_bytes(4)),
            'config'       => [],
            'is_active'    => true,
        ];
    }
}
