<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Enums\InputExpectedType;
use App\Domains\Flow\Support\CallbackDataCodec;
use DateTimeImmutable;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

/**
 * Default {@see InputValidatorInterface} implementation.
 *
 * One class, one match — every textual/select/platform-native input type is
 * a single private method. Media types are NOT handled here: their ingestion
 * lives in {@see \App\Domains\Flow\Handlers\InputNodeHandler::handleMediaInput}
 * because it requires media services the validator should not depend on.
 */
final class InputValidator implements InputValidatorInterface
{
    public function validate(
        InputExpectedType $type,
        IncomingMessage $incoming,
        array $rules,
        array $nodeConfig,
    ): ValidationResult {
        return match ($type) {
            InputExpectedType::Text   => $this->validateText($incoming, $rules),
            InputExpectedType::Number => $this->validateNumber($incoming, $rules),
            InputExpectedType::Email  => $this->validateEmail($incoming),
            InputExpectedType::Phone  => $this->validatePhone($incoming, $rules),
            InputExpectedType::Date   => $this->validateDate($incoming, $rules),
            InputExpectedType::Select,
            InputExpectedType::Confirm  => $this->validateSelect($incoming, $nodeConfig),
            InputExpectedType::Contact  => $this->validateContact($incoming),
            InputExpectedType::Location => $this->validateLocation($incoming),
            default                     => ValidationResult::fail('unsupported_type'),
        };
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function validateText(IncomingMessage $incoming, array $rules): ValidationResult
    {
        if (IncomingMessageType::Text !== $incoming->type || ! is_string($incoming->text)) {
            return ValidationResult::fail('text_required');
        }

        $value = $incoming->text;

        $minLength = isset($rules['min_length']) ? (int)$rules['min_length'] : null;
        $maxLength = isset($rules['max_length']) ? (int)$rules['max_length'] : null;
        $pattern   = is_string($rules['pattern'] ?? null) && '' !== $rules['pattern'] ? (string)$rules['pattern'] : null;

        if (null !== $minLength && mb_strlen($value) < $minLength) {
            return ValidationResult::fail('text_too_short');
        }
        if (null !== $maxLength && mb_strlen($value) > $maxLength) {
            return ValidationResult::fail('text_too_long');
        }
        if (null !== $pattern && 1 !== @preg_match($pattern, $value)) {
            return ValidationResult::fail('text_pattern_mismatch');
        }

        return ValidationResult::ok($value);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function validateNumber(IncomingMessage $incoming, array $rules): ValidationResult
    {
        if (IncomingMessageType::Text !== $incoming->type || ! is_string($incoming->text)) {
            return ValidationResult::fail('text_required');
        }

        $normalized = str_replace([',', ' '], ['.', ''], mb_trim($incoming->text));

        if ('' === $normalized || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return ValidationResult::fail('not_a_number');
        }

        $integerOnly = (bool)($rules['integer_only'] ?? false);
        $hasFraction = str_contains($normalized, '.');

        if ($integerOnly && $hasFraction) {
            return ValidationResult::fail('integer_required');
        }

        $value = $integerOnly ? (int)$normalized : (float)$normalized;

        if (isset($rules['min']) && $value < (float)$rules['min']) {
            return ValidationResult::fail('number_too_small');
        }
        if (isset($rules['max']) && $value > (float)$rules['max']) {
            return ValidationResult::fail('number_too_large');
        }

        return ValidationResult::ok($value);
    }

    private function validateEmail(IncomingMessage $incoming): ValidationResult
    {
        if (IncomingMessageType::Text !== $incoming->type || ! is_string($incoming->text)) {
            return ValidationResult::fail('text_required');
        }

        $value = mb_trim($incoming->text);

        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ValidationResult::fail('invalid_email');
        }

        return ValidationResult::ok($value);
    }

    /**
     * Phone validation via libphonenumber. Candidate regions come from the input
     * node's `country` (single, optional narrow) or — falling back — the
     * assistant's `countries` list injected by the handler.
     *
     *  - international input ("+…") is parsed region-agnostically; if candidates
     *    are configured the detected region must be one of them;
     *  - national input (no "+") is parsed against each candidate region until
     *    one yields a valid number — impossible to infer without candidates.
     *
     * The stored value is always normalized to E.164.
     *
     * @param  array<string, mixed>  $rules
     */
    private function validatePhone(IncomingMessage $incoming, array $rules): ValidationResult
    {
        if (IncomingMessageType::Text !== $incoming->type || ! is_string($incoming->text)) {
            return ValidationResult::fail('text_required');
        }

        $raw = mb_trim($incoming->text);
        if ('' === $raw) {
            return ValidationResult::fail('invalid_phone');
        }

        $candidates = $this->phoneCandidates($rules);
        $util       = PhoneNumberUtil::getInstance();

        if (str_starts_with($raw, '+')) {
            try {
                $proto = $util->parse($raw, null);
            } catch (NumberParseException) {
                return ValidationResult::fail('invalid_phone');
            }

            if (! $util->isValidNumber($proto)) {
                return ValidationResult::fail('invalid_phone');
            }

            $region = $util->getRegionCodeForNumber($proto);
            if ([] !== $candidates && (null === $region || ! in_array($region, $candidates, true))) {
                return ValidationResult::fail('invalid_phone');
            }

            return ValidationResult::ok($util->format($proto, PhoneNumberFormat::E164));
        }

        // National format needs a region to disambiguate.
        foreach ($candidates as $region) {
            try {
                $proto = $util->parse($raw, $region);
            } catch (NumberParseException) {
                continue;
            }

            if ($util->isValidNumber($proto)) {
                return ValidationResult::ok($util->format($proto, PhoneNumberFormat::E164));
            }
        }

        return ValidationResult::fail('invalid_phone');
    }

    /**
     * Resolve the ordered list of candidate ISO regions: a per-node `country`
     * wins (narrow to one), otherwise the assistant's `countries` list.
     *
     * @param  array<string, mixed>  $rules
     *
     * @return list<string>
     */
    private function phoneCandidates(array $rules): array
    {
        $perNode = is_string($rules['country'] ?? null) && '' !== mb_trim((string) $rules['country'])
            ? mb_strtoupper(mb_trim((string) $rules['country']))
            : null;

        if (null !== $perNode) {
            return [$perNode];
        }

        $assistant = is_array($rules['countries'] ?? null) ? $rules['countries'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $c): ?string => is_string($c) && '' !== mb_trim($c) ? mb_strtoupper(mb_trim($c)) : null,
            $assistant,
        )));
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function validateDate(IncomingMessage $incoming, array $rules): ValidationResult
    {
        if (IncomingMessageType::Text !== $incoming->type || ! is_string($incoming->text)) {
            return ValidationResult::fail('text_required');
        }

        $format = is_string($rules['format'] ?? null) && '' !== $rules['format']
            ? (string)$rules['format']
            : 'Y-m-d';

        $value = mb_trim($incoming->text);

        try {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
        } catch (Throwable) {
            return ValidationResult::fail('invalid_date');
        }

        // createFromFormat returns false for invalid input but also accepts
        // partial matches (e.g. "2026-02-30" silently overflows). Round-tripping
        // through ->format() catches both.
        if (false === $parsed || $parsed->format($format) !== $value) {
            return ValidationResult::fail('invalid_date');
        }

        return ValidationResult::ok($parsed->format('Y-m-d'));
    }

    /**
     * Resolve an inline-keyboard press to a button value. The callback_data is
     * decoded via {@see CallbackDataCodec}; the decoded button_id is looked up
     * in `config.buttons` and the matching button's `value` is returned.
     * Free-text replies (the user typed instead of pressing) are rejected.
     *
     * @param  array<string, mixed>  $nodeConfig
     */
    private function validateSelect(IncomingMessage $incoming, array $nodeConfig): ValidationResult
    {
        if (IncomingMessageType::CallbackQuery !== $incoming->type) {
            return ValidationResult::fail('button_press_required');
        }

        $decoded = CallbackDataCodec::decode($incoming->text);
        if (null === $decoded) {
            return ValidationResult::fail('invalid_callback');
        }

        $buttonId = $decoded['button_id'];
        $config   = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $buttons  = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];

        foreach ($buttons as $button) {
            if (! is_array($button) || ($button['id'] ?? null) !== $buttonId) {
                continue;
            }

            return ValidationResult::ok((string)($button['value'] ?? ''));
        }

        return ValidationResult::fail('unknown_button');
    }

    /**
     * Telegram contact share. Returns a structured payload so flows can read
     * `flow.contact.phone_number` or feed it into downstream nodes verbatim.
     */
    private function validateContact(IncomingMessage $incoming): ValidationResult
    {
        if (IncomingMessageType::Contact !== $incoming->type) {
            return ValidationResult::fail('contact_required');
        }

        $contact = is_array($incoming->payload['contact'] ?? null) ? $incoming->payload['contact'] : null;

        if (null === $contact || ! isset($contact['phone_number'])) {
            return ValidationResult::fail('contact_required');
        }

        return ValidationResult::ok([
            'phone_number' => (string)$contact['phone_number'],
            'first_name'   => isset($contact['first_name']) ? (string)$contact['first_name'] : null,
            'last_name'    => isset($contact['last_name']) ? (string)$contact['last_name'] : null,
            'user_id'      => isset($contact['user_id']) ? (string)$contact['user_id'] : null,
        ]);
    }

    private function validateLocation(IncomingMessage $incoming): ValidationResult
    {
        if (IncomingMessageType::Location !== $incoming->type) {
            return ValidationResult::fail('location_required');
        }

        $location = is_array($incoming->payload['location'] ?? null) ? $incoming->payload['location'] : null;

        if (null === $location || ! isset($location['latitude'], $location['longitude'])) {
            return ValidationResult::fail('location_required');
        }

        return ValidationResult::ok([
            'latitude'  => (float)$location['latitude'],
            'longitude' => (float)$location['longitude'],
        ]);
    }
}
