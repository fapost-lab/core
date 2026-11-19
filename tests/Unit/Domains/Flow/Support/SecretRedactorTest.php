<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Support;

use App\Domains\Flow\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    public function test_a_secret_is_replaced_in_a_string(): void
    {
        $this->assertSame('Bearer ***', SecretRedactor::redact('Bearer topsecret', ['topsecret']));
    }

    public function test_it_walks_arrays_recursively_and_replaces_in_keys_too(): void
    {
        $redacted = SecretRedactor::redact(
            ['a' => 'x topsecret y', 'topsecret' => ['deep' => ['topsecret']], 'n' => 5, 'b' => null],
            ['topsecret'],
        );

        $this->assertSame(['a' => 'x *** y', '***' => ['deep' => ['***']], 'n' => 5, 'b' => null], $redacted);
    }

    public function test_urlencoded_variants_are_replaced(): void
    {
        $secret = 'a b&c/d';

        $this->assertSame(
            'q=*** and q=*** and raw ***',
            SecretRedactor::redact('q=' . rawurlencode($secret) . ' and q=' . urlencode($secret) . ' and raw ' . $secret, [$secret]),
        );
    }

    public function test_a_secret_shorter_than_the_minimum_is_ignored(): void
    {
        $this->assertSame('abc abc', SecretRedactor::redact('abc abc', ['abc']));
        $this->assertSame('abcd', SecretRedactor::redact('abcd', ['abc']));
        $this->assertSame('***', SecretRedactor::redact('abcd', ['abcd']));
    }

    public function test_the_longest_secret_goes_first(): void
    {
        $this->assertSame('***', SecretRedactor::redact('secretvalue', ['secret', 'secretvalue']));
        $this->assertSame('***', SecretRedactor::redact('secretvalue', ['secretvalue', 'secret']));
    }

    public function test_multibyte_length_counts_characters(): void
    {
        $this->assertSame('***', SecretRedactor::redact('пароль', ['пароль']));
        $this->assertSame('abc', SecretRedactor::redact('abc', ['абв']));
    }

    public function test_no_usable_secrets_returns_the_value_untouched(): void
    {
        $value = ['a' => 'abc', 'b' => [1, 2]];

        $this->assertSame($value, SecretRedactor::redact($value, []));
        $this->assertSame($value, SecretRedactor::redact($value, ['', 'ab']));
    }

    public function test_scalars_other_than_strings_pass_through(): void
    {
        $this->assertSame(7, SecretRedactor::redact(7, ['secret']));
        $this->assertTrue(SecretRedactor::redact(true, ['secret']));
        $this->assertNull(SecretRedactor::redact(null, ['secret']));
    }

    public function test_every_occurrence_is_replaced(): void
    {
        $this->assertSame('*** + ***', SecretRedactor::redact('secret1 + secret1', ['secret1']));
    }
}
