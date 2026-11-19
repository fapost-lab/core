<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Credentials;

use App\Domains\Credentials\SecretRedactor;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SecretRedactorTest extends TestCase
{
    public function test_a_secret_is_replaced_in_a_string(): void
    {
        $this->assertSame('Bearer ***!', SecretRedactor::redact('Bearer s3cr3t-token!', ['s3cr3t-token']));
    }

    public function test_it_walks_arrays_over_keys_and_values(): void
    {
        $result = SecretRedactor::redact([
            'url'      => 'https://x.test/?key=abcd1234',
            'abcd1234' => 'as a key',
            'nested'   => ['list' => ['abcd1234', 'plain'], 'n' => 5, 'b' => true, 'null' => null, 'f' => 1.5],
            0          => 'abcd1234',
        ], ['abcd1234']);

        $this->assertSame([
            'url'    => 'https://x.test/?key=***',
            '***'    => 'as a key',
            'nested' => ['list' => ['***', 'plain'], 'n' => 5, 'b' => true, 'null' => null, 'f' => 1.5],
            0        => '***',
        ], $result);
    }

    public function test_url_encoded_forms_are_masked(): void
    {
        $secret = 'a b&c/d+e';

        $this->assertSame('q=*** and ***', SecretRedactor::redact('q=' . urlencode($secret) . ' and ' . $secret, [$secret]));
        $this->assertSame('***', SecretRedactor::redact(rawurlencode($secret), [$secret]));
    }

    public function test_the_longest_secret_is_masked_whole(): void
    {
        $this->assertSame('***', SecretRedactor::redact('abcd-efgh-ijkl', ['abcd', 'abcd-efgh-ijkl']));
        $this->assertSame('*** ***', SecretRedactor::redact('abcd-efgh abcd', ['abcd', 'abcd-efgh']));
    }

    public function test_a_secret_shorter_than_four_characters_is_skipped(): void
    {
        $this->assertSame('abc and ab', SecretRedactor::redact('abc and ab', ['abc', 'ab', '']));
        $this->assertSame('*** and abc', SecretRedactor::redact('abcd and abc', ['abc', 'abcd']));
    }

    public function test_values_that_are_not_strings_or_arrays_pass_untouched(): void
    {
        $object = new stdClass();

        $this->assertSame(1234, SecretRedactor::redact(1234, ['1234']));
        $this->assertNull(SecretRedactor::redact(null, ['abcd']));
        $this->assertSame($object, SecretRedactor::redact($object, ['abcd']));
    }

    public function test_no_usable_secret_returns_the_value_as_it_is(): void
    {
        $value = ['a' => 'secret'];

        $this->assertSame($value, SecretRedactor::redact($value, []));
        $this->assertSame($value, SecretRedactor::redact($value, ['x']));
    }
}
