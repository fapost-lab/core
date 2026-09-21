<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Tests\Feature\FeatureTestCase;

/**
 * The node handler registry is a worker-lifetime singleton, while the
 * collaborators its handlers talk to (message sender, content translator,
 * tenant settings, media services) are scoped and rebuilt for every queue
 * job. A handler the registry resolves must reach the instances of the job it
 * is resolved in, never the ones that existed when the registry was built.
 */
final class NodeHandlerScopedDependenciesTest extends FeatureTestCase
{
    public function test_send_message_uses_the_sender_and_tenant_settings_of_the_current_job(): void
    {
        $firstJobSender = $this->startJob(contentBaseLanguage: 'en');
        $registry       = $this->app->make(NodeHandlerRegistryInterface::class);
        // The first job uses the handler too: a registry that cached the
        // instance would hand exactly this one to the second job.
        $registry->resolve(SendMessageNodeHandler::TYPE, 1);

        $this->app->forgetScopedInstances();
        $secondJobSender = $this->startJob(contentBaseLanguage: 'de');

        $registry->resolve(SendMessageNodeHandler::TYPE, 1)->execute(
            ['id' => 'send-1', 'config' => ['content_type' => 'text', 'text' => ['en' => 'Hello', 'de' => 'Hallo']]],
            [],
            $this->context(nodeId: 'send-1', resolvedLanguage: 'uk'),
        );

        $this->assertSame([], $firstJobSender->payloads);
        $this->assertCount(1, $secondJobSender->payloads);
        $this->assertSame('Hallo', $secondJobSender->payloads[0]['text']);
    }

    public function test_input_prompt_uses_the_sender_of_the_current_job(): void
    {
        $firstJobSender = $this->startJob(contentBaseLanguage: 'en');
        $registry       = $this->app->make(NodeHandlerRegistryInterface::class);
        $registry->resolve(InputNodeHandler::TYPE, 1);

        $this->app->forgetScopedInstances();
        $secondJobSender = $this->startJob(contentBaseLanguage: 'en');

        $registry->resolve(InputNodeHandler::TYPE, 1)->execute(
            ['id' => 'input-1', 'config' => ['prompt' => 'What is your name?']],
            [],
            $this->context(nodeId: 'input-1'),
        );

        $this->assertSame([], $firstJobSender->payloads);
        $this->assertCount(1, $secondJobSender->payloads);
        $this->assertSame('What is your name?', $secondJobSender->payloads[0]['text']);
    }

    /**
     * Seed the scoped state a queue job would build: its own message sender
     * and its own tenant settings.
     */
    private function startJob(string $contentBaseLanguage): RecordingMessageSender
    {
        TenantSettings::fake(['content_base_language' => $contentBaseLanguage]);

        $sender = new RecordingMessageSender();
        $this->app->instance(MessageSenderInterface::class, $sender);

        return $sender;
    }

    private function context(string $nodeId, string $resolvedLanguage = 'en'): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: 'tenant-1',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: $nodeId,
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            resolvedLanguage: $resolvedLanguage,
        );
    }
}

final class RecordingMessageSender implements MessageSenderInterface
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
    {
        $this->payloads[] = $payload;

        return 'ext-' . count($this->payloads);
    }
}
