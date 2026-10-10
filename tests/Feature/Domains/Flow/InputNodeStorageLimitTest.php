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
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class InputNodeStorageLimitTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'),
        );
    }

    public function test_a_file_that_does_not_fit_sends_the_session_straight_to_invalid(): void
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

        $result = $this->app->make(InputNodeHandler::class)->execute(
            ['id' => 'input-1', 'config' => ['expected_type' => 'image', 'variable' => null]],
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
                    type: IncomingMessageType::Photo,
                    platform: 'telegram',
                    media: [new IncomingMedia('AgAC', MediaKind::Image)],
                ),
            ),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('invalid', $result->sourceHandle);
        $this->assertSame('storage_limit_reached', $result->metadata['error_key']);
        $this->assertArrayNotHasKey('retry_count', $result->metadata, 'There is no retry and no hint.');
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
