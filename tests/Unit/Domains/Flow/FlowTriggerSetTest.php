<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Services\FlowTriggerSet;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowTriggerSetTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformedPayloads(): iterable
    {
        $id = '0b0e6c0e-6a7e-4a43-9c53-0f2b6d6a9d11';

        yield 'a string' => ['triggers'];
        yield 'null' => [null];
        yield 'an object keyed by id' => [[$id => ['id' => $id, 'type' => 'message', 'config' => []]]];
        yield 'an entry that is not an object' => [['message']];
        yield 'a missing id' => [[['type' => 'message', 'config' => []]]];
        yield 'a non-string id' => [[['id' => 5, 'type' => 'message', 'config' => []]]];
        yield 'an id that is not a UUID' => [[['id' => 'nope', 'type' => 'message', 'config' => []]]];
        yield 'an empty id' => [[['id' => '', 'type' => 'message', 'config' => []]]];
        yield 'a missing type' => [[['id' => $id, 'config' => []]]];
        yield 'an empty type' => [[['id' => $id, 'type' => '', 'config' => []]]];
        yield 'a non-string type' => [[['id' => $id, 'type' => ['message'], 'config' => []]]];
        yield 'a missing config' => [[['id' => $id, 'type' => 'message']]];
        yield 'a non-object config' => [[['id' => $id, 'type' => 'message', 'config' => 'keywords']]];
        yield 'a non-boolean active flag' => [[['id' => $id, 'type' => 'message', 'is_active' => 0, 'config' => []]]];
        yield 'a non-integer priority' => [[['id' => $id, 'type' => 'message', 'priority' => '5', 'config' => []]]];
        yield 'a negative priority' => [[['id' => $id, 'type' => 'message', 'priority' => -1, 'config' => []]]];
        yield 'one bad entry among good ones' => [[['id' => $id, 'type' => 'message', 'config' => []], ['id' => $id, 'type' => 'message']]];
    }
    public function test_same_config_ignores_the_order_of_object_keys_at_every_level(): void
    {
        $left  = ['cron' => '0 9 * * *', 'timezone' => 'UTC', 'extra' => ['b' => 1, 'a' => ['y' => 2, 'x' => 3]]];
        $right = ['extra' => ['a' => ['x' => 3, 'y' => 2], 'b' => 1], 'timezone' => 'UTC', 'cron' => '0 9 * * *'];

        $this->assertTrue(FlowTriggerSet::sameConfig($left, $right));
    }

    public function test_same_config_keeps_the_order_of_a_list(): void
    {
        $this->assertFalse(FlowTriggerSet::sameConfig(['keywords' => ['a', 'b']], ['keywords' => ['b', 'a']]));
        $this->assertFalse(FlowTriggerSet::sameConfig(['nested' => [['k' => 1], ['k' => 2]]], ['nested' => [['k' => 2], ['k' => 1]]]));
    }

    public function test_same_config_tells_different_values_and_keys_apart(): void
    {
        $this->assertFalse(FlowTriggerSet::sameConfig(['a' => 1], ['a' => 2]));
        $this->assertFalse(FlowTriggerSet::sameConfig(['a' => 1], ['a' => 1, 'b' => null]));
        $this->assertTrue(FlowTriggerSet::sameConfig([], []));
    }

    public function test_from_payload_accepts_an_empty_list(): void
    {
        $this->assertSame([], FlowTriggerSet::fromPayload([]));
    }

    public function test_from_payload_fills_defaults_and_lowercases_a_uuid(): void
    {
        $id = (string) Str::uuid();

        $triggers = FlowTriggerSet::fromPayload([['id' => mb_strtoupper($id), 'type' => 'message', 'config' => []]]);

        $this->assertSame([['id' => $id, 'type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => []]], $triggers);
    }

    public function test_from_payload_accepts_the_uuid_form_of_a_ulid(): void
    {
        $id = mb_strtolower(Str::ulid()->toRfc4122());

        $triggers = FlowTriggerSet::fromPayload([['id' => $id, 'type' => 'schedule', 'is_active' => false, 'priority' => 3, 'config' => ['cron' => '* * * * *']]]);

        $this->assertSame($id, $triggers[0]['id']);
        $this->assertFalse($triggers[0]['is_active']);
        $this->assertSame(3, $triggers[0]['priority']);
    }

    #[DataProvider('malformedPayloads')]
    public function test_from_payload_rejects_a_malformed_list(mixed $payload): void
    {
        $this->expectException(InvalidTriggerPayloadException::class);

        FlowTriggerSet::fromPayload($payload);
    }

    public function test_normalize_drops_entries_that_are_not_objects_and_fills_defaults(): void
    {
        $normalized = FlowTriggerSet::normalize(['junk', ['id' => 'ABC', 'type' => 'api', 'priority' => '5', 'is_active' => 'yes', 'config' => 'x']]);

        $this->assertSame([['id' => 'abc', 'type' => 'api', 'is_active' => true, 'priority' => 100, 'config' => []]], $normalized);
    }
}
