<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Trace;

use App\Domains\Flow\Trace\TracePayloadLimiter;
use PHPUnit\Framework\TestCase;

final class TracePayloadLimiterTest extends TestCase
{
    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private TracePayloadLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = new TracePayloadLimiter();
    }

    public function test_a_small_envelope_is_unchanged(): void
    {
        $envelope = ['type' => 'call', 'status' => 'executed', 'input' => ['a' => 1], 'output' => ['b' => 'x']];

        $limited = $this->limiter->limit($envelope);

        $this->assertSame($envelope, $limited);
        $this->assertArrayNotHasKey('truncated', $limited);
    }

    public function test_an_envelope_over_a_mebibyte_is_cut_to_the_limit(): void
    {
        $big = str_repeat('x', TracePayloadLimiter::MAX_ENVELOPE_BYTES + 10);

        $limited = $this->limiter->limit([
            'type'   => 'call',
            'status' => 'executed',
            'input'  => ['small' => 'kept'],
            'output' => ['body' => $big],
        ]);

        $this->assertLessThanOrEqual(TracePayloadLimiter::MAX_ENVELOPE_BYTES, $this->encodedSize($limited));
        $this->assertTrue($limited['truncated']);
        $this->assertSame('[truncated, ' . (TracePayloadLimiter::MAX_ENVELOPE_BYTES + 10 + 2) . ' bytes]', $limited['output']['body']);
        $this->assertSame('call', $limited['type']);
        $this->assertSame('executed', $limited['status']);
        $this->assertSame(['small' => 'kept'], $limited['input']);
    }

    public function test_the_marker_names_the_encoded_size_of_the_replaced_value(): void
    {
        $value = str_repeat('я', 200);

        $limited = $this->limiter->limit(['type' => 't', 'output' => ['big' => $value, 'slash' => str_repeat('/', 50)]], 300);

        $expected = mb_strlen((string) json_encode($value, self::FLAGS), '8bit');

        $this->assertTrue($limited['truncated']);
        $this->assertLessThanOrEqual(300, $this->encodedSize($limited));
        $this->assertSame(sprintf('[truncated, %d bytes]', $expected), $this->leafContaining($limited, '[truncated'));
    }

    public function test_the_biggest_values_go_first_and_the_rest_survive(): void
    {
        $limited = $this->limiter->limit([
            'type'   => 't',
            'input'  => ['a' => str_repeat('a', 400), 'b' => str_repeat('b', 120)],
            'output' => ['c' => str_repeat('c', 900)],
        ], 700);

        $this->assertLessThanOrEqual(700, $this->encodedSize($limited));
        $this->assertStringStartsWith('[truncated, ', $limited['output']['c']);
        $this->assertSame(str_repeat('b', 120), $limited['input']['b']);
    }

    public function test_many_small_leaves_fall_back_to_replacing_the_whole_part(): void
    {
        $leaves = [];

        for ($i = 0; $i < 400; ++$i) {
            $leaves['k' . $i] = 'value-' . $i;
        }

        $limited = $this->limiter->limit(['type' => 't', 'status' => 'executed', 'input' => $leaves, 'output' => ['ok' => 1]], 1500);

        $this->assertLessThanOrEqual(1500, $this->encodedSize($limited));
        $this->assertTrue($limited['truncated']);
        $this->assertStringStartsWith('[truncated, ', $limited['input']);
        $this->assertSame('t', $limited['type']);
        $this->assertSame('executed', $limited['status']);
    }

    public function test_invalid_utf8_and_nul_do_not_break_the_envelope(): void
    {
        $limited = $this->limiter->limit([
            'type'   => 't',
            'output' => ['bad' => "ab\xB1\xB2cd", "k\0ey" => "nul\0byte"],
        ]);

        $this->assertIsString(json_encode($limited, JSON_THROW_ON_ERROR));
        $this->assertSame("ab\u{FFFD}\u{FFFD}cd", $limited['output']['bad']);
        $this->assertSame('nulbyte', $limited['output']['key']);
    }

    public function test_a_value_json_cannot_hold_is_dropped_instead_of_failing(): void
    {
        $limited = $this->limiter->limit(['type' => 't', 'output' => ['inf' => INF, 'ok' => 'fine']]);

        $this->assertSame('fine', $limited['output']['ok']);
        $this->assertIsString(json_encode($limited, JSON_THROW_ON_ERROR));
    }

    public function test_a_long_error_message_is_capped(): void
    {
        $limited = $this->limiter->limit(['type' => 't', 'error' => ['class' => 'X', 'message' => str_repeat('m', TracePayloadLimiter::MAX_ERROR_BYTES * 2)]]);

        $this->assertSame(str_repeat('m', TracePayloadLimiter::MAX_ERROR_BYTES) . '…', $limited['error']['message']);
        $this->assertSame('X', $limited['error']['class']);
        $this->assertArrayNotHasKey('truncated', $limited);
    }

    public function test_a_multibyte_error_message_is_cut_on_a_character_boundary(): void
    {
        $limited = $this->limiter->limit(['error' => ['message' => str_repeat('я', TracePayloadLimiter::MAX_ERROR_BYTES)]]);

        $message = $limited['error']['message'];

        $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
        $this->assertLessThanOrEqual(TracePayloadLimiter::MAX_ERROR_BYTES + mb_strlen('…', '8bit'), mb_strlen($message, '8bit'));
        $this->assertStringEndsWith('…', $message);
    }

    public function test_an_error_message_at_the_cap_is_kept_whole(): void
    {
        $message = str_repeat('m', TracePayloadLimiter::MAX_ERROR_BYTES);

        $this->assertSame($message, $this->limiter->limit(['error' => ['message' => $message]])['error']['message']);
    }

    public function test_an_envelope_without_payloads_is_never_truncated_below_its_service_fields(): void
    {
        $limited = $this->limiter->limit(['type' => str_repeat('t', 500), 'status' => 'executed'], 100);

        $this->assertSame(str_repeat('t', 500), $limited['type']);
        $this->assertSame('executed', $limited['status']);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function encodedSize(array $envelope): int
    {
        return mb_strlen((string) json_encode($envelope, self::FLAGS), '8bit');
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function leafContaining(array $value, string $prefix): ?string
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                $found = $this->leafContaining($item, $prefix);

                if (null !== $found) {
                    return $found;
                }
            } elseif (is_string($item) && str_starts_with($item, $prefix)) {
                return $item;
            }
        }

        return null;
    }
}
