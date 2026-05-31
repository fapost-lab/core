<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Validation;

use App\Domains\Flow\Enums\InputExpectedType;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Flow\Validation\InputValidator;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    private InputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new InputValidator();
    }

    public function test_text_accepts_any_text_when_no_rules(): void
    {
        $result = $this->validator->validate(
            InputExpectedType::Text,
            $this->text('hello world'),
            [],
            [],
        );

        $this->assertTrue($result->valid);
        $this->assertSame('hello world', $result->value);
    }

    public function test_text_enforces_min_max_length_and_pattern(): void
    {
        $short = $this->validator->validate(InputExpectedType::Text, $this->text('ab'), ['min_length' => 3], []);
        $long  = $this->validator->validate(InputExpectedType::Text, $this->text('abcdef'), ['max_length' => 3], []);
        $bad   = $this->validator->validate(InputExpectedType::Text, $this->text('abc'), ['pattern' => '/^\d+$/'], []);

        $this->assertFalse($short->valid);
        $this->assertSame('text_too_short', $short->errorKey);
        $this->assertSame('text_too_long', $long->errorKey);
        $this->assertSame('text_pattern_mismatch', $bad->errorKey);
    }

    public function test_text_rejects_non_text_message_type(): void
    {
        $result = $this->validator->validate(
            InputExpectedType::Text,
            new IncomingMessage('1', 'u', 'c', null, IncomingMessageType::Photo, 'telegram'),
            [],
            [],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('text_required', $result->errorKey);
    }

    #[DataProvider('numberCases')]
    public function test_number_parsing_and_bounds(string $input, array $rules, bool $expectedValid, mixed $expectedValue): void
    {
        $result = $this->validator->validate(InputExpectedType::Number, $this->text($input), $rules, []);

        $this->assertSame($expectedValid, $result->valid);
        if ($expectedValid) {
            $this->assertSame($expectedValue, $result->value);
        }
    }

    public static function numberCases(): array
    {
        return [
            'plain integer'                 => ['42', [], true, 42.0],
            'european decimal comma'        => ['3,14', [], true, 3.14],
            'integer_only rejects fraction' => ['3.14', ['integer_only' => true], false, null],
            'integer_only accepts integer'  => ['7', ['integer_only' => true], true, 7],
            'below min'                     => ['5', ['min' => 10], false, null],
            'above max'                     => ['100', ['max' => 50], false, null],
            'not a number'                  => ['abc', [], false, null],
            'in range'                      => ['25', ['min' => 0, 'max' => 100], true, 25.0],
        ];
    }

    public function test_email_validates_via_filter(): void
    {
        $good = $this->validator->validate(InputExpectedType::Email, $this->text('  user@example.com  '), [], []);
        $bad  = $this->validator->validate(InputExpectedType::Email, $this->text('not-an-email'), [], []);

        $this->assertTrue($good->valid);
        $this->assertSame('user@example.com', $good->value);
        $this->assertSame('invalid_email', $bad->errorKey);
    }

    public function test_phone_normalizes_and_matches_country_pattern(): void
    {
        $ua = $this->validator->validate(
            InputExpectedType::Phone,
            $this->text('+380 (63) 123-45-67'),
            ['country' => 'UA'],
            [],
        );
        $wrong = $this->validator->validate(
            InputExpectedType::Phone,
            $this->text('+1 415 555 0123'),
            ['country' => 'UA'],
            [],
        );
        $generic = $this->validator->validate(
            InputExpectedType::Phone,
            $this->text('+1 415-555-0123'),
            [],
            [],
        );

        $this->assertTrue($ua->valid);
        $this->assertSame('+380631234567', $ua->value);
        $this->assertSame('invalid_phone', $wrong->errorKey);
        $this->assertTrue($generic->valid);
        $this->assertSame('+14155550123', $generic->value);
    }

    public function test_date_round_trips_through_format(): void
    {
        $good = $this->validator->validate(InputExpectedType::Date, $this->text('2026-05-31'), [], []);
        // createFromFormat would silently overflow Feb 30 → mar 02 — round-trip catches it.
        $bad  = $this->validator->validate(InputExpectedType::Date, $this->text('2026-02-30'), [], []);
        $custom = $this->validator->validate(InputExpectedType::Date, $this->text('31/12/2026'), ['format' => 'd/m/Y'], []);

        $this->assertTrue($good->valid);
        $this->assertSame('2026-05-31', $good->value);
        $this->assertFalse($bad->valid);
        $this->assertTrue($custom->valid);
        $this->assertSame('2026-12-31', $custom->value);
    }

    public function test_select_resolves_button_value_from_callback_data(): void
    {
        $sessionId = '550e8400-e29b-41d4-a716-446655440000';
        $buttonId  = '11111111-1111-4111-8111-111111111111';
        $payload   = CallbackDataCodec::encode($sessionId, $buttonId);

        $nodeConfig = [
            'config' => [
                'buttons' => [
                    ['id' => $buttonId, 'value' => 'option_a', 'label' => 'A'],
                    ['id' => '22222222-2222-4222-8222-222222222222', 'value' => 'option_b', 'label' => 'B'],
                ],
            ],
        ];

        $incoming = new IncomingMessage('1', 'u', 'c', $payload, IncomingMessageType::CallbackQuery, 'telegram');
        $result   = $this->validator->validate(InputExpectedType::Select, $incoming, [], $nodeConfig);

        $this->assertTrue($result->valid);
        $this->assertSame('option_a', $result->value);
    }

    public function test_select_rejects_free_text_replies(): void
    {
        $result = $this->validator->validate(
            InputExpectedType::Select,
            $this->text('I want option A'),
            [],
            ['config' => ['buttons' => [['id' => 'x', 'value' => 'a']]]],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('button_press_required', $result->errorKey);
    }

    public function test_select_rejects_unknown_button_id(): void
    {
        $payload = CallbackDataCodec::encode(
            '550e8400-e29b-41d4-a716-446655440000',
            '99999999-9999-4999-8999-999999999999',
        );
        $incoming = new IncomingMessage('1', 'u', 'c', $payload, IncomingMessageType::CallbackQuery, 'telegram');

        $result = $this->validator->validate(
            InputExpectedType::Select,
            $incoming,
            [],
            ['config' => ['buttons' => [['id' => '11111111-1111-4111-8111-111111111111', 'value' => 'a']]]],
        );

        $this->assertFalse($result->valid);
        $this->assertSame('unknown_button', $result->errorKey);
    }

    public function test_contact_extracts_phone_and_names_from_payload(): void
    {
        $incoming = new IncomingMessage(
            updateId: '1',
            externalUserId: 'u',
            externalChatId: 'c',
            text: null,
            type: IncomingMessageType::Contact,
            platform: 'telegram',
            payload: ['contact' => ['phone_number' => '+380631234567', 'first_name' => 'Bob']],
        );

        $result = $this->validator->validate(InputExpectedType::Contact, $incoming, [], []);

        $this->assertTrue($result->valid);
        $this->assertSame('+380631234567', $result->value['phone_number']);
        $this->assertSame('Bob', $result->value['first_name']);
    }

    public function test_contact_rejects_wrong_message_type(): void
    {
        $result = $this->validator->validate(InputExpectedType::Contact, $this->text('+380631234567'), [], []);

        $this->assertFalse($result->valid);
        $this->assertSame('contact_required', $result->errorKey);
    }

    public function test_location_returns_lat_lon(): void
    {
        $incoming = new IncomingMessage(
            updateId: '1',
            externalUserId: 'u',
            externalChatId: 'c',
            text: null,
            type: IncomingMessageType::Location,
            platform: 'telegram',
            payload: ['location' => ['latitude' => 50.45, 'longitude' => 30.52]],
        );

        $result = $this->validator->validate(InputExpectedType::Location, $incoming, [], []);

        $this->assertTrue($result->valid);
        $this->assertSame(['latitude' => 50.45, 'longitude' => 30.52], $result->value);
    }

    private function text(string $value): IncomingMessage
    {
        return new IncomingMessage(
            updateId: '1',
            externalUserId: 'u',
            externalChatId: 'c',
            text: $value,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }
}
