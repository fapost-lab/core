<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use ArrayObject;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class InputNodeProviderKindTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

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
            $this->runInput('voice', IncomingMessageType::Voice, new IncomingMedia('AwAD', MediaKind::Audio, 'audio/ogg'), $seen);
        } catch (RuntimeException) {
            // The stub ingests nothing; only what it was asked to store matters here.
        }

        $this->assertSame('voice', $seen['kind']);
    }

    private function runInput(string $expectedType, IncomingMessageType $type, IncomingMedia $media, ArrayObject $seen): NodeExecutionResult
    {
        $assistant = Assistant::factory()->create();
        $contact   = Contact::factory()->create(['tenant_id' => self::TENANT_ID]);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => true,
        ]));
        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);
        $session = $this->startedSession($assistant, $contact);

        $this->app->instance(MediaIngestorInterface::class, new class ($seen) implements MediaIngestorInterface {
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
        });

        return $this->app->make(InputNodeHandler::class)->execute(
            ['id' => 'input-1', 'config' => ['expected_type' => $expectedType, 'variable' => null]],
            [],
            new NodeExecutionContext(
                tenantId: self::TENANT_ID,
                contactId: (string) $contact->getKey(),
                sessionId: (string) $session->getKey(),
                nodeId: 'input-1',
                idempotencyKey: 'idem-1',
                platform: 'telegram',
                incoming: new IncomingMessage(
                    updateId: 'upd-1',
                    externalUserId: 'ext-user',
                    externalChatId: 'ext-chat',
                    text: null,
                    type: $type,
                    platform: 'telegram',
                    media: [$media],
                ),
            ),
        );
    }

    private function startedSession(Assistant $assistant, Contact $contact): FlowSession
    {
        $definition = FlowDefinition::query()->create([
            'tenant_id'         => self::TENANT_ID,
            'flow_id'           => Str::uuid()->toString(),
            'version'           => 1,
            'name'              => 'Test',
            'nodes'             => [],
            'edges'             => [],
            'is_active'         => true,
            'expression_engine' => 'template',
            'logging_enabled'   => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'input-1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 1,
        ]);
    }
}
