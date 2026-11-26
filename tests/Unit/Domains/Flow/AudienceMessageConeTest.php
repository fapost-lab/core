<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Support\AudienceMessageCone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AudienceMessageConeTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function handlesTheEngineTellsApart(): array
    {
        return [
            'empty against default'      => ['', 'default'],
            'integer against its string' => [1, '1'],
            'boolean against its string' => [true, '1'],
            'empty against missing'      => ['', null],
        ];
    }

    #[DataProvider('handlesTheEngineTellsApart')]
    public function test_an_edge_handle_the_engine_follows_differently_changes_the_cone(mixed $published, mixed $drafted): void
    {
        $this->assertNotSame(
            $this->coneOfBroadcastWithHandle($published),
            $this->coneOfBroadcastWithHandle($drafted),
        );
    }

    public function test_a_missing_or_null_handle_is_the_default_one(): void
    {
        $default = $this->coneOfBroadcastWithHandle('default');

        $this->assertSame($default, $this->coneOfBroadcastWithHandle(null));
        $this->assertSame($default, $this->coneOfBroadcastWithHandle(null, omitHandle: true));
    }

    public function test_an_edge_with_a_handle_the_engine_never_follows_still_leads_to_the_node(): void
    {
        $cones = AudienceMessageCone::of(
            [
                ['id' => 'set', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => []]],
                self::broadcast(),
            ],
            [['id' => 'e1', 'from' => 'set', 'to' => 'broadcast', 'handle' => 7]],
            ['broadcast'],
        );

        $this->assertStringContainsString('"set"', $cones['broadcast'], 'A wider cone is the cautious side of the check.');
    }

    public function test_is_audience_message_names_only_a_message_to_tags_or_a_segment(): void
    {
        $this->assertTrue(AudienceMessageCone::isAudienceMessage(self::broadcast()));
        $this->assertFalse(AudienceMessageCone::isAudienceMessage([
            'id'     => 'reply',
            'type'   => 'send_message',
            'config' => ['addressee' => ['kind' => 'contact', 'contact' => '{{flow.id}}'], 'text' => ['en' => 'Hi']],
        ]));
        $this->assertFalse(AudienceMessageCone::isAudienceMessage(['id' => 'x', 'type' => 'assign', 'config' => []]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function broadcast(): array
    {
        return [
            'id'      => 'broadcast',
            'type'    => 'send_message',
            'version' => 1,
            'config'  => ['addressee' => ['kind' => 'tags', 'tags' => ['vip']], 'text' => ['en' => 'Hello']],
        ];
    }

    private function coneOfBroadcastWithHandle(mixed $handle, bool $omitHandle = false): string
    {
        $edge = ['id' => 'e1', 'from' => 'start', 'to' => 'broadcast'];

        if (! $omitHandle) {
            $edge['handle'] = $handle;
        }

        return AudienceMessageCone::of(
            [['id' => 'start', 'type' => 'assign', 'version' => 1, 'config' => ['operations' => []]], self::broadcast()],
            [$edge],
            ['broadcast'],
        )['broadcast'];
    }
}
