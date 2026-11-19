<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Trace;

use App\Domains\Flow\Trace\TracePayloadMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TracePayloadMaskerTest extends TestCase
{
    private TracePayloadMasker $masker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masker = new TracePayloadMasker();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function sensitiveKeys(): iterable
    {
        yield 'whole' => ['password'];
        yield 'upper case' => ['PASSWORD'];
        yield 'mixed case header' => ['Authorization'];
        yield 'dotted suffix' => ['headers.Authorization'];
        yield 'dotted password' => ['auth.password'];
        yield 'dashed suffix' => ['x-telegram-bot-api-secret-token'];
        yield 'underscore suffix' => ['bot_secret'];
        yield 'compound header' => ['X-Api-Key'];
        yield 'set-cookie' => ['Set-Cookie'];
        yield 'oauth' => ['refresh_token'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function innocentKeys(): iterable
    {
        yield 'data' => ['data'];
        yield 'plural' => ['tokens_count'];
        yield 'prefix only' => ['tokenizer'];
        yield 'glued suffix' => ['mypassword'];
        yield 'empty' => [''];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function urlParameters(): iterable
    {
        yield 'key' => ['key'];
        yield 'sig' => ['sig'];
        yield 'signature' => ['signature'];
        yield 'code' => ['code'];
        yield 'upper case' => ['API_KEY'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function sensitiveQueryParameterKeys(): iterable
    {
        yield 'key' => ['query.key'];
        yield 'sig' => ['query.sig'];
        yield 'signature' => ['query.signature'];
        yield 'code' => ['query.code'];
        yield 'upper case name' => ['query.KEY'];
        yield 'upper case prefix' => ['QUERY.Signature'];
        yield 'sensitive key' => ['query.api_key'];
        yield 'token' => ['query.access_token'];
    }

    public function test_a_sensitive_key_is_masked_at_any_depth(): void
    {
        $masked = $this->masker->mask([
            'password' => 'p',
            'a'        => ['b' => ['c' => ['token' => 'deep', 'keep' => 'visible']]],
            'list'     => [['secret' => 's'], ['name' => 'n']],
        ]);

        $this->assertSame('***', $masked['password']);
        $this->assertSame('***', $masked['a']['b']['c']['token']);
        $this->assertSame('visible', $masked['a']['b']['c']['keep']);
        $this->assertSame('***', $masked['list'][0]['secret']);
        $this->assertSame('n', $masked['list'][1]['name']);
    }

    public function test_a_whole_subtree_under_a_sensitive_key_is_masked(): void
    {
        $this->assertSame(['token' => '***'], $this->masker->mask(['token' => ['a' => 1, 'b' => ['c' => 2]]]));
    }

    #[DataProvider('sensitiveKeys')]
    public function test_a_sensitive_key_matches_case_insensitively_whole_or_as_a_suffix(string $key): void
    {
        $this->assertTrue($this->masker->isSensitiveKey($key));
        $this->assertSame([$key => '***'], $this->masker->mask([$key => 'value']));
    }

    #[DataProvider('innocentKeys')]
    public function test_an_innocent_key_is_left_alone(string $key): void
    {
        $this->assertFalse($this->masker->isSensitiveKey($key));
        $this->assertSame([$key => 'value'], $this->masker->mask([$key => 'value']));
    }

    public function test_non_string_leaves_and_integer_keys_pass_through(): void
    {
        $value = [1, 2.5, true, null, ['x' => false]];

        $this->assertSame($value, $this->masker->mask($value));
        $this->assertSame(42, $this->masker->mask(42));
        $this->assertNull($this->masker->mask(null));
    }

    public function test_the_query_secret_of_a_url_is_masked_and_the_rest_kept(): void
    {
        $this->assertSame(
            'https://h.example/x?access_token=***&x=1',
            $this->masker->mask('https://h.example/x?access_token=abc&x=1'),
        );
    }

    public function test_the_password_of_a_url_is_masked_and_the_user_kept(): void
    {
        $this->assertSame('https://user:***@h.example/path', $this->masker->mask('https://user:pass@h.example/path'));
    }

    #[DataProvider('urlParameters')]
    public function test_url_only_parameters_are_masked(string $parameter): void
    {
        $this->assertSame(
            "https://h.example/x?a=1&{$parameter}=***",
            $this->masker->mask("https://h.example/x?a=1&{$parameter}=zzz"),
        );
    }

    #[DataProvider('sensitiveQueryParameterKeys')]
    public function test_a_query_call_parameter_is_masked_like_the_same_url_parameter(string $key): void
    {
        $this->assertSame([$key => '***', 'query.page' => '2'], $this->masker->mask([$key => 'AIzaSECRET', 'query.page' => '2']));
    }

    public function test_url_parameter_names_are_not_masked_outside_a_query(): void
    {
        $value = ['key' => 'k', 'code' => 'c', 'body.key' => 'b', 'sort_key' => 's', 'cache_key' => 'x'];

        $this->assertSame($value, $this->masker->mask($value));
    }

    public function test_the_fragment_of_a_url_is_kept(): void
    {
        $this->assertSame(
            'https://h.example/x?token=***&y=2#section-1',
            $this->masker->mask('https://h.example/x?token=abc&y=2#section-1'),
        );
    }

    public function test_a_url_without_a_secret_is_unchanged(): void
    {
        $url = 'https://h.example/path/a?page=2&sort=asc#top';

        $this->assertSame($url, $this->masker->mask($url));
    }

    public function test_an_encoded_parameter_name_is_still_recognised(): void
    {
        $this->assertSame('https://h.example/?access%5Ftoken=***', $this->masker->mask('https://h.example/?access%5Ftoken=abc'));
    }

    public function test_a_url_inside_an_error_sentence_is_masked(): void
    {
        $message = 'cURL error 28: Operation timed out for https://h.example/x?token=abc123 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)';

        $masked = $this->masker->mask($message);

        $this->assertStringContainsString('https://h.example/x?token=***', $masked);
        $this->assertStringNotContainsString('abc123', $masked);
        $this->assertStringContainsString('(see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', $masked);
    }

    public function test_every_url_of_a_text_is_masked(): void
    {
        $masked = $this->masker->mask('a https://one.example/?key=1 b http://two.example/?sig=2 c');

        $this->assertSame('a https://one.example/?key=*** b http://two.example/?sig=*** c', $masked);
    }

    public function test_urls_in_nested_values_are_masked(): void
    {
        $masked = $this->masker->mask(['target' => ['url' => 'https://h.example/?token=abc']]);

        $this->assertSame('https://h.example/?token=***', $masked['target']['url']);
    }

    public function test_a_query_pair_without_a_value_is_kept(): void
    {
        $this->assertSame('https://h.example/?flag&token=***', $this->masker->mask('https://h.example/?flag&token=1'));
    }

    public function test_literal_secrets_are_removed_from_every_string_and_key(): void
    {
        $masked = $this->masker->mask(
            ['note' => 'sent with s3cr3tvalue ok', 'nested' => ['s3cr3tvalue' => 'x', 'n' => 1]],
            ['s3cr3tvalue'],
        );

        $this->assertSame('sent with *** ok', $masked['note']);
        $this->assertSame(['***' => 'x', 'n' => 1], $masked['nested']);
    }

    public function test_an_empty_input_is_returned_as_is(): void
    {
        $this->assertSame([], $this->masker->mask([]));
        $this->assertSame('', $this->masker->mask(''));
    }

    public function test_text_without_a_url_is_left_alone(): void
    {
        $this->assertSame('plain text, token=abc', $this->masker->maskUrls('plain text, token=abc'));
    }
}
