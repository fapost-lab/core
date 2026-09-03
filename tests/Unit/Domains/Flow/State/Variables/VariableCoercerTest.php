<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\State\Variables;

use App\Domains\Flow\State\Variables\VariableCoercer;
use App\Domains\Flow\State\Variables\VariableType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VariableCoercerTest extends TestCase
{
    private VariableCoercer $coercer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coercer = new VariableCoercer();
    }

    /**
     * @return array<string, array{0: mixed, 1: int|float|null}>
     */
    public static function numberCoercionProvider(): array
    {
        return [
            'integer string'   => ['42', 42],
            'float string'     => ['3.14', 3.14],
            'negative int'     => ['-7', -7],
            'integer native'   => [42, 42],
            'float native'     => [3.14, 3.14],
            'empty string'     => ['', null],
            'null'             => [null, null],
            'alpha string'     => ['abc', null],
            'non-scalar array' => [['a'], null],
        ];
    }

    // ── Text / pass-through types ────────────────────────────────────────────

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function confirmTruthyProvider(): array
    {
        return [
            'bool true'     => [true],
            'string true'   => ['true'],
            'string 1'      => ['1'],
            'string yes'    => ['yes'],
            'string y'      => ['y'],
            'string on'     => ['on'],
            'string да'     => ['да'],
            'uppercase YES' => ['YES'],
        ];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function confirmFalsyProvider(): array
    {
        return [
            'bool false'   => [false],
            'string false' => ['false'],
            'string 0'     => ['0'],
            'string no'    => ['no'],
            'string n'     => ['n'],
            'string off'   => ['off'],
            'string нет'   => ['нет'],
        ];
    }

    public function test_text_returns_string_as_is(): void
    {
        $this->assertSame('hello', $this->coercer->coerce('hello', VariableType::Text));
    }

    public function test_text_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->coercer->coerce('', VariableType::Text));
    }

    public function test_text_returns_null_for_null(): void
    {
        $this->assertNull($this->coercer->coerce(null, VariableType::Text));
    }

    public function test_phone_returns_string_as_is(): void
    {
        $this->assertSame('+380501234567', $this->coercer->coerce('+380501234567', VariableType::Phone));
    }

    // ── Number ──────────────────────────────────────────────────────────────

    public function test_email_returns_string(): void
    {
        $this->assertSame('a@b.com', $this->coercer->coerce('a@b.com', VariableType::Email));
    }

    public function test_select_returns_string(): void
    {
        $this->assertSame('option_a', $this->coercer->coerce('option_a', VariableType::Select));
    }

    // ── Confirm ──────────────────────────────────────────────────────────────

    #[DataProvider('numberCoercionProvider')]
    public function test_number_coercion(mixed $input, int|float|null $expected): void
    {
        $this->assertSame($expected, $this->coercer->coerce($input, VariableType::Number));
    }

    #[DataProvider('confirmTruthyProvider')]
    public function test_confirm_truthy_literals(mixed $input): void
    {
        $this->assertTrue($this->coercer->coerce($input, VariableType::Confirm));
    }

    #[DataProvider('confirmFalsyProvider')]
    public function test_confirm_falsy_literals(mixed $input): void
    {
        $this->assertFalse($this->coercer->coerce($input, VariableType::Confirm));
    }

    public function test_confirm_unknown_string_returns_null(): void
    {
        $this->assertNull($this->coercer->coerce('maybe', VariableType::Confirm));
    }

    public function test_confirm_null_returns_null(): void
    {
        $this->assertNull($this->coercer->coerce(null, VariableType::Confirm));
    }

    public function test_date_parses_iso_string(): void
    {
        $result = $this->coercer->coerce('2024-03-15', VariableType::Date);

        $this->assertNotNull($result);
        $this->assertSame('2024-03-15', $result->format('Y-m-d'));
    }

    // ── Date ─────────────────────────────────────────────────────────────────

    public function test_date_returns_null_for_null(): void
    {
        $this->assertNull($this->coercer->coerce(null, VariableType::Date));
    }

    public function test_date_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->coercer->coerce('', VariableType::Date));
    }

    public function test_date_returns_null_for_unparseable_string(): void
    {
        $this->assertNull($this->coercer->coerce('not-a-date', VariableType::Date));
    }

    public function test_contact_passes_through_array(): void
    {
        $payload = ['id' => '123', 'phone' => '+380501234567'];

        $this->assertSame($payload, $this->coercer->coerce($payload, VariableType::Contact));
    }

    // ── Structured pass-through types ────────────────────────────────────────

    public function test_contact_null_for_empty_string(): void
    {
        $this->assertNull($this->coercer->coerce('', VariableType::Contact));
    }

    public function test_file_passes_through_value(): void
    {
        $this->assertSame('file-id-abc', $this->coercer->coerce('file-id-abc', VariableType::File));
    }

    public function test_json_passes_through_nested_structure(): void
    {
        $payload = ['status' => 200, 'body' => ['data' => ['id' => 7]], 'headers' => ['X' => 'y']];

        $this->assertSame($payload, $this->coercer->coerce($payload, VariableType::Json));
    }

    public function test_json_null_for_empty_string(): void
    {
        $this->assertNull($this->coercer->coerce('', VariableType::Json));
    }
}
