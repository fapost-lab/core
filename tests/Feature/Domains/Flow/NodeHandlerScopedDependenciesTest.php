<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Call\CallExecutionLedgerInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InMemoryCallExecutionLedger;
use Tests\Support\InMemoryCredentialStore;

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

    public function test_call_reads_the_credential_of_the_current_job(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);
        $credentialId = '0197c0de-0000-7000-8000-000000000001';
        $node         = ['id' => 'call-1', 'config' => [
            'transport'     => 'http',
            'target'        => 'GET https://api.example.com/x',
            'credential_id' => $credentialId,
        ]];

        $this->startCallJob('tenant-1', $credentialId, 'token-of-the-first-tenant');
        $registry = $this->app->make(NodeHandlerRegistryInterface::class);
        // The first job uses the handler too: a registry that cached it would hand exactly this one to the second job.
        $registry->resolve(CallNodeHandler::TYPE, 1)->execute($node, [], $this->context(nodeId: 'call-1'));

        $this->app->forgetScopedInstances();
        $this->startCallJob('tenant-2', $credentialId, 'token-of-the-second-tenant');

        $registry->resolve(CallNodeHandler::TYPE, 1)->execute($node, [], new NodeExecutionContext(
            tenantId: 'tenant-2',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'call-1',
            idempotencyKey: 'idem-2',
            platform: 'telegram',
        ));

        $authorizations = [];

        Http::assertSent(static function (Request $request) use (&$authorizations): bool {
            $authorizations[] = $request->header('Authorization')[0] ?? null;

            return true;
        });

        $this->assertSame(['Bearer token-of-the-first-tenant', 'Bearer token-of-the-second-tenant'], $authorizations);
    }

    /**
     * Seed the scoped state a call job would build: its tenant, its credential store and its ledger.
     */
    private function startCallJob(string $tenantId, string $credentialId, string $token): void
    {
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $tenantId, schemaName: 'main'));

        $store = new InMemoryCredentialStore();
        $store->add(new CredentialSecret($tenantId, $credentialId, 'bearer', 'v1', [], ['api.example.com'], ['token' => $token]));
        $this->app->instance(CredentialStoreInterface::class, $store);
        $this->app->instance(CallExecutionLedgerInterface::class, new InMemoryCallExecutionLedger());
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
