<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use ArrayObject;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Media\Enums\MediaKind;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\Flow\RunsInputNodeWithMedia;

final class InputNodeProviderKindTest extends FeatureTestCase
{
    use RunsInputNodeWithMedia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'),
        );
    }

    public function test_the_way_the_message_carried_the_file_reaches_the_ingestor(): void
    {
        $seen = new ArrayObject();

        try {
            $this->runInputWithMedia(
                new class ($seen) implements MediaIngestorInterface {
                    public function __construct(private readonly ArrayObject $seen)
                    {
                    }

                    public function ingestFromChannel(
                        Channel $channel,
                        string $providerFileId,
                        ?MediaFolder $folder = null,
                        ?string $uploadedBy = null,
                        MediaSource $source = MediaSource::InputNode,
                        ?string $providerKind = null,
                    ): MediaFile {
                        $this->seen['kind'] = $providerKind;

                        throw new RuntimeException('Not stored.');
                    }
                },
                'voice',
                IncomingMessageType::Voice,
                new IncomingMedia('AwAD', MediaKind::Audio, 'audio/ogg'),
            );
        } catch (RuntimeException) {
            // The stub ingests nothing; only what it was asked to store matters here.
        }

        $this->assertSame('voice', $seen['kind']);
    }
}
