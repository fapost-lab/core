<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\Jobs\FetchConversationMediaJob;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use Mockery;
use Tests\Feature\FeatureTestCase;

final class FetchConversationMediaJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_a_refused_file_degrades_to_failed_with_its_reason_and_the_job_does_not_throw(): void
    {
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => Assistant::factory()->create()->getKey(),
            'tenant_id'    => self::TENANT_ID,
        ]));

        $this->app->instance(MediaIngestorInterface::class, new class () implements MediaIngestorInterface {
            public function ingestFromChannel(
                Channel $channel,
                string $providerFileId,
                ?MediaFolder $folder = null,
                ?string $uploadedBy = null,
                MediaSource $source = MediaSource::InputNode,
            ): MediaFile {
                throw new StorageLimitReachedException('media_storage', 10, 10, 5);
            }
        });

        $store = Mockery::mock(ConversationStoreInterface::class);
        $store->shouldReceive('updateMessageMedia')
            ->once()
            ->with('msg-1', [[
                'provider_file_id' => 'AgAC',
                'status'           => 'failed',
                'reason'           => 'storage_limit_reached',
                'kind'             => 'photo',
            ]]);
        $this->app->instance(ConversationStoreInterface::class, $store);

        $job = new FetchConversationMediaJob(
            tenantId: self::TENANT_ID,
            messageId: 'msg-1',
            channelId: (string) $channel->getKey(),
            media: [['provider_file_id' => 'AgAC', 'kind' => 'photo', 'status' => 'pending']],
        );

        $this->app->call([$job, 'handle']);
    }
}
