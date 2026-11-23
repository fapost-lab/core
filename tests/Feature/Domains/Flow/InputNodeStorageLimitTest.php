<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Media\Enums\MediaKind;
use Tests\Feature\FeatureTestCase;
use Tests\Support\Flow\RunsInputNodeWithMedia;

final class InputNodeStorageLimitTest extends FeatureTestCase
{
    use RunsInputNodeWithMedia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'),
        );
    }

    public function test_a_file_that_does_not_fit_sends_the_session_straight_to_invalid(): void
    {
        $result = $this->runInputWithMedia(
            new class () implements MediaIngestorInterface {
                public function ingestFromChannel(
                    Channel $channel,
                    string $providerFileId,
                    ?MediaFolder $folder = null,
                    ?string $uploadedBy = null,
                    MediaSource $source = MediaSource::InputNode,
                    ?string $providerKind = null,
                ): MediaFile {
                    throw new StorageLimitReachedException('media_storage', 10, 10, 5);
                }
            },
            'image',
            IncomingMessageType::Photo,
            new IncomingMedia('AgAC', MediaKind::Image),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('invalid', $result->sourceHandle);
        $this->assertSame('storage_limit_reached', $result->metadata['error_key']);
        $this->assertArrayNotHasKey('retry_count', $result->metadata, 'There is no retry and no hint.');
    }
}
