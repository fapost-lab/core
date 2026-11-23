<?php

declare(strict_types=1);

namespace Tests\Support\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Str;

/**
 * Runs the input node on a message that carries one file, with the given ingestor standing in for the real one. The
 * using test class is a feature test whose tenant context is already set to {@see self::TENANT_ID}.
 */
trait RunsInputNodeWithMedia
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private function runInputWithMedia(
        MediaIngestorInterface $ingestor,
        string $expectedType,
        IncomingMessageType $type,
        IncomingMedia $media,
    ): NodeExecutionResult {
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

        $this->app->instance(MediaIngestorInterface::class, $ingestor);

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
