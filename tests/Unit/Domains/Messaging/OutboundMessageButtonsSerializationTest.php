<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging;

use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\ButtonAction;
use Fapost\Foundation\Messaging\ButtonSet;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Tests\TestCase;

/**
 * An outbound message crosses the queue serialized; the neutral buttons it carries must come back intact.
 */
final class OutboundMessageButtonsSerializationTest extends TestCase
{
    public function test_a_message_with_buttons_survives_the_queue_round_trip(): void
    {
        $buttons = new ButtonSet([
            [new Button('Yes', payload: 'p-1'), new Button('No', payload: 'p-2')],
        ]);

        $message = new OutboundMessage(
            idempotencyKey: 'idem-1',
            tenantId: 'tenant-1',
            channelId: 'channel-1',
            channelType: 'telegram',
            transportToken: null,
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'keyboard', text: 'Choose', buttons: $buttons),
        );

        /** @var OutboundMessage $restored */
        $restored = unserialize(serialize($message));

        $this->assertEquals($buttons, $restored->payload->buttons);
        $this->assertSame(ButtonAction::Callback, $restored->payload->buttons->rows[0][1]->action);
        $this->assertSame('p-2', $restored->payload->buttons->rows[0][1]->payload);
    }
}
