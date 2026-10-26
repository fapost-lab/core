<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Testing\ConnectorTestCase;
use Tests\Fixtures\Connectors\Echo\EchoKitConnectorServiceProvider;

/**
 * The connector testing kit proven against the fixture connector `echo`, used exactly as a connector package's test
 * uses it: through the kit's methods, `Fapost\Foundation\…` and Laravel.
 */
final class ConnectorTestCaseTest extends ConnectorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootConnector(EchoKitConnectorServiceProvider::class, dirname(__DIR__, 2) . '/Fixtures/Connectors/Echo/kit-package/composer.json');
    }

    public function test_a_webhook_reaches_the_real_route_and_is_queued_with_its_document(): void
    {
        $channel = $this->createChannel('echo', secretToken: 'echo-secret');

        $this->postWebhook($channel, '{"id":7,"from":"u1","text":"hi"}', ['X-Echo-Secret' => 'echo-secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $queued = $this->dispatchedInbound();

        $this->assertCount(1, $queued);
        $this->assertSame('echo', $queued[0]->platform);
        $this->assertSame($channel->getId(), $queued[0]->channelId);
        $this->assertSame('echo:' . $channel->getId() . ':7', $queued[0]->idempotencyKey);
        $this->assertSame(['id' => 7, 'from' => 'u1', 'text' => 'hi'], $queued[0]->rawPayload);
    }

    public function test_a_wrong_secret_is_answered_ok_and_queues_nothing(): void
    {
        $channel = $this->createChannel('echo', secretToken: 'echo-secret');

        $this->postWebhook($channel, '{"id":7}', ['X-Echo-Secret' => 'forged'])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame([], $this->dispatchedInbound());
    }

    public function test_the_same_delivery_twice_is_queued_once(): void
    {
        $channel = $this->createChannel('echo', secretToken: 'echo-secret');

        $this->postWebhook($channel, '{"id":7}', ['X-Echo-Secret' => 'echo-secret'])->assertOk();
        $this->postWebhook($channel, '{"id":7}', ['X-Echo-Secret' => 'echo-secret'])->assertOk();

        $this->assertCount(1, $this->dispatchedInbound());
    }

    public function test_a_queued_delivery_is_normalized_by_the_registered_adapter(): void
    {
        $channel = $this->createChannel('echo', secretToken: 'echo-secret');

        $this->postWebhook($channel, '{"id":9,"from":"u2","text":"hello"}', ['X-Echo-Secret' => 'echo-secret']);

        $message = $this->normalizeInbound($this->dispatchedInbound()[0]);

        $this->assertSame('9', $message->updateId);
        $this->assertSame('u2', $message->externalUserId);
        $this->assertSame('hello', $message->text);
        $this->assertSame(IncomingMessageType::Text, $message->type);
    }

    public function test_a_message_is_sent_through_the_connectors_sender_and_deduplicated(): void
    {
        $channel = $this->createChannel('echo');
        $message = new OutboundMessage(
            idempotencyKey: 'kit-send-1',
            tenantId: $this->tenantId(),
            channelId: $channel->getId(),
            channelType: 'echo',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'pong'),
        );

        $first = $this->send($message);

        $this->assertTrue($first->sent);
        $this->assertFalse($first->duplicate);
        $this->assertTrue($this->send($message)->duplicate);
    }

    public function test_the_registrar_stores_what_the_connector_reports(): void
    {
        $channel = $this->createChannel('echo', token: 'acme');

        $this->registerWebhook($channel);

        $this->assertSame('acme', $this->channelConfig($channel)['account_name']);
    }

    public function test_the_channel_config_is_what_was_stored(): void
    {
        $channel = $this->createChannel('echo', config: ['region' => 'us']);

        $this->assertSame('us', $this->channelConfig($channel)['region']);
    }
}
