<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
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

    public function test_the_kind_telegram_reported_for_the_file_reaches_the_ingestor(): void
    {
        $incoming = (new TelegramInboundNormalizer())->normalize([
            'update_id' => 1,
            'message'   => [
                'message_id' => 10,
                'chat'       => ['id' => 1],
                'from'       => ['id' => 2],
                'voice'      => ['file_id' => 'AwAD', 'mime_type' => 'audio/ogg'],
            ],
        ]);

        $this->assertSame('voice', $this->kindTheIngestorWasGiven($incoming->type, $incoming->media[0]));
    }

    public function test_the_kind_comes_from_the_file_not_from_the_message_type(): void
    {
        $this->assertSame('document', $this->kindTheIngestorWasGiven(
            IncomingMessageType::Voice,
            new IncomingMedia('AwAD', MediaKind::Audio, 'audio/ogg', providerKind: 'document'),
        ));
    }

    public function test_a_file_without_a_reported_kind_is_ingested_without_one(): void
    {
        $this->assertNull($this->kindTheIngestorWasGiven(
            IncomingMessageType::Voice,
            new IncomingMedia('AwAD', MediaKind::Audio, 'audio/ogg'),
        ));
    }

    private function kindTheIngestorWasGiven(IncomingMessageType $type, IncomingMedia $media): ?string
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
                $type,
                $media,
            );
        } catch (RuntimeException) {
            // The stub ingests nothing; only what it was asked to store matters here.
        }

        $this->assertTrue($seen->offsetExists('kind'), 'The ingestor was not called.');

        return $seen['kind'];
    }
}
