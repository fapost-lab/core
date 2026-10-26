<?php

declare(strict_types=1);

namespace Fapost\Testing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Solution\Contracts\ConnectorRegistrarFactoryInterface;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Connector\Manifest\ConnectorManifest;
use Fapost\Foundation\Connector\Manifest\ConnectorManifestParser;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Testing\Concerns\IsolatesLandlordPerParallelProcess;
use Fapost\Testing\Concerns\PreparesCoreDatabase;
use Fapost\Testing\Support\InMemoryRedis;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use ReflectionClass;

/**
 * Base class for the tests of a connector package that Core runs, installed through the overlay.
 *
 * It boots the real Core application (the test always runs inside a Core checkout the connector is installed into),
 * puts the same database in place as Core's own feature tests ({@see PreparesCoreDatabase}) and offers what a connector
 * test does with a channel of its type: boot the connector ({@see bootConnector()}), create a channel, deliver a webhook to the real route, see what ingress queued, run
 * it through the connector's adapter, send a message through the connector's sender and run its webhook registrar.
 * A test using only this class, `Fapost\Foundation\…` and Laravel needs no import from `App\…`.
 *
 * Nothing reaches a real Redis or queue. The routing registry, the ingress deduplication and the outbound idempotency
 * keep their keys in memory ({@see InMemoryRedis}), and an inbound delivery is recorded instead of run: the test
 * decides when to run it ({@see normalizeInbound()}).
 *
 * The connector's own services (sender, registrar, adapter) are real, so a test that must not call the provider fakes
 * the HTTP client with `Http::fake()`.
 *
 * @experimental may change in minor versions before 1.0
 */
abstract class ConnectorTestCase extends BaseTestCase
{
    use IsolatesLandlordPerParallelProcess;
    use PreparesCoreDatabase;

    private const string TENANT_SLUG = 'main';

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a Vite manifest: a test asserts on the response, not on compiled assets.
        $this->withoutVite();

        // Redis is Core's registry cache, its ingress deduplication and its outbound idempotency: in memory here.
        $this->app->instance('redis', new InMemoryRedis());
        Redis::clearResolvedInstance('redis');

        // Only the inbound job is recorded; the webhook registrar's job and anything else still run.
        Bus::fake([IncomingMessageJob::class]);
    }

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__) . '/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * An active channel of the connector's type in the default tenant, routed so the real webhook route finds it.
     * Creating it runs the webhook registrar, as it does in Core.
     *
     * @param  array<string, mixed>  $config  the stored `channels.config`
     */
    protected function createChannel(string $type, string $token = 'test-token', string $secretToken = 'test-secret', array $config = []): ChannelInterface
    {
        return $this->inTenant(function () use ($type, $token, $secretToken, $config): Channel {
            $assistant = Assistant::factory()->create();

            return Channel::factory()->create([
                'assistant_id' => $assistant->getKey(),
                'type'         => $type,
                'token'        => $token,
                'secret_token' => $secretToken,
                'config'       => $config,
                'is_active'    => true,
            ]);
        });
    }

    /**
     * POSTs the exact bytes of `$rawBody` to the real `webhook.handle` route of the channel's type and hash.
     *
     * @param  array<string, string>  $headers  request headers by name; `Content-Type` defaults to `application/json`
     */
    protected function postWebhook(ChannelInterface $channel, string $rawBody, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $key                                                                                    = mb_strtoupper(str_replace('-', '_', $name));
            $server[('CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key) ? $key : 'HTTP_' . $key] = $value;
        }

        $uri = route('webhook.handle', ['channel' => $channel->getType(), 'hash' => $this->webhookHash($channel)], false);

        return $this->call('POST', $uri, [], [], [], $server, $rawBody);
    }

    /**
     * What ingress queued so far: the payload of every inbound job it dispatched, oldest first. The jobs are not run.
     *
     * @return list<InboundWebhookPayload>
     */
    protected function dispatchedInbound(): array
    {
        return Bus::dispatched(IncomingMessageJob::class)
            ->map(static fn (IncomingMessageJob $job): InboundWebhookPayload => $job->payload)
            ->values()
            ->all();
    }

    /**
     * What the job does first: runs the payload through the adapter registered for its channel type, inside the tenant.
     * An adapter's `InboundEventIgnoredException` reaches the test, which asserts it.
     */
    protected function normalizeInbound(InboundWebhookPayload $payload): IncomingMessage
    {
        return $this->inTenant(fn (): IncomingMessage => $this->app->make(ChannelAdapterResolver::class)
            ->resolve($payload->platform)
            ->normalize($payload->rawPayload));
    }

    /**
     * Sends through Core's `MessageSenderInterface` in the default tenant, as a flow does: idempotency, the per-chat
     * rate limit, the connector's sender and the outbound transcript.
     */
    protected function send(OutboundMessage $message): DeliveryResult
    {
        return $this->inTenant(fn (): DeliveryResult => $this->app->make(MessageSenderInterface::class)->send($message));
    }

    /**
     * Runs the channel's webhook registrar the way Core does after a save, synchronously, so what the connector
     * stores through Core's managed settings is in `channels.config` afterwards.
     */
    protected function registerWebhook(ChannelInterface $channel): void
    {
        $this->inTenant(function () use ($channel): void {
            $stored = Channel::query()->findOrFail($channel->getId());
            $tenant = $this->app->make(TenantContextInterface::class)->get();

            SyncChannelWebhookJob::dispatchSync(
                tenantId: $tenant->getId(),
                schema: $tenant->getSchemaName(),
                channelId: (string) $stored->getKey(),
                channelType: $stored->type,
                webhookPublicHash: $stored->webhook_public_hash,
                token: null,
                secretToken: null,
                config: [],
                register: true,
            );
        });
    }

    /**
     * The channel's `config` as stored now, with whatever the connector's registrar added.
     *
     * @return array<string, mixed>
     */
    protected function channelConfig(ChannelInterface $channel): array
    {
        $config = Channel::query()->findOrFail($channel->getId())->config;

        return is_array($config) ? $config : [];
    }

    /**
     * The id of the tenant the kit's channels live in, for the `tenantId` of an {@see OutboundMessage}.
     */
    protected function tenantId(): string
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug(self::TENANT_SLUG);
        $this->assertNotNull($tenant, 'The default tenant does not exist.');

        return $tenant->getId();
    }

    /**
     * Boots the connector under test as Core boots an installed one: its manifest is read and validated, its provider
     * is registered and its channels go through Core's connector registrar, with the same checks.
     *
     * Call it in `setUp()`. A connector checked out next to Core is not in Core's composer.json, so discovery does not
     * see it; this is the step discovery would have taken. A connector installed into Core (through the overlay, as in
     * the package's CI) is already discovered and booted when the test starts: the manifest is still read and the
     * provider still checked, but nothing is registered a second time, so the same test runs in both places.
     *
     * @param  class-string<AbstractConnectorServiceProvider>  $provider
     * @param  string|null  $composerPath  the package's composer.json; null to look for it above the provider's file
     */
    protected function bootConnector(string $provider, ?string $composerPath = null): void
    {
        $composerPath ??= $this->composerJsonAbove((string) (new ReflectionClass($provider))->getFileName());
        $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
        $result   = (new ConnectorManifestParser())->parse(is_array($composer) ? $composer : []);

        $this->assertTrue($result->isValid(), "The connector manifest in {$composerPath} is not valid:\n" . implode("\n", $result->lines()));

        $connector = InstalledConnector::fromManifest(ConnectorManifest::fromComposer($composer), null, $composerPath);

        $this->assertSame($provider, $connector->provider, "The manifest in {$composerPath} names another provider.");

        if ($this->app->getProvider($provider) instanceof AbstractConnectorServiceProvider) {
            return;
        }

        $instance = $this->app->register($provider);

        $this->assertInstanceOf(AbstractConnectorServiceProvider::class, $instance);

        $instance->registerChannelsWith($this->app->make(ConnectorRegistrarFactoryInterface::class)->forConnector($connector));
        $this->app->forgetScopedInstances();
    }

    protected function webhookHash(ChannelInterface $channel): string
    {
        return Channel::query()->findOrFail($channel->getId())->webhook_public_hash;
    }

    private function composerJsonAbove(string $file): string
    {
        $dir = dirname($file);

        while (dirname($dir) !== $dir) {
            if (is_file($dir . '/composer.json')) {
                return $dir . '/composer.json';
            }

            $dir = dirname($dir);
        }

        $this->fail("No composer.json above {$file}.");
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private function inTenant(callable $callback): mixed
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug(self::TENANT_SLUG);
        $this->assertNotNull($tenant, 'The default tenant does not exist.');

        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }
}
