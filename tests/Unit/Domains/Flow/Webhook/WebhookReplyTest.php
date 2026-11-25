<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Webhook;

use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A kept reply read back from the reply store: what {@see WebhookReply::toJson()} wrote comes back, anything else is no
 * reply rather than an error.
 */
final class WebhookReplyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReplies(): iterable
    {
        yield 'an unknown body type' => ['{"status":200,"body":"{}","type":"xml"}'];
        yield 'a body type not a string' => ['{"status":200,"body":"{}","type":[]}'];
        yield 'no body type' => ['{"status":200,"body":"{}"}'];
        yield 'a status out of range' => ['{"status":700,"body":"{}","type":"json"}'];
        yield 'not JSON' => ['{"status":'];
    }
    public function test_a_kept_reply_reads_back_as_written(): void
    {
        $reply = WebhookReply::fromJson((new WebhookReply(201, 'done', WebhookReplyType::Text))->toJson());

        $this->assertNotNull($reply);
        $this->assertSame(201, $reply->status);
        $this->assertSame('done', $reply->body);
        $this->assertSame(WebhookReplyType::Text, $reply->type);
    }

    #[DataProvider('unreadableReplies')]
    public function test_a_kept_value_that_is_not_a_reply_is_none(string $json): void
    {
        $this->assertNull(WebhookReply::fromJson($json));
    }
}
