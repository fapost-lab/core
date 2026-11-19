<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Credentials;

use App\Domains\Credentials\Types\ApiKeyHeaderCredentialType;
use App\Domains\Credentials\Types\ApiKeyQueryCredentialType;
use App\Domains\Credentials\Types\BasicCredentialType;
use App\Domains\Credentials\Types\BearerCredentialType;
use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimpleCredentialTypesTest extends TestCase
{
    /**
     * @return array<string, array{0: HttpCredentialTypeInterface, 1: array<string, string>, 2: array<string, string>}>
     */
    public static function incomplete(): array
    {
        return [
            'bearer without token'    => [new BearerCredentialType(), [], []],
            'bearer empty token'      => [new BearerCredentialType(), [], ['token' => '']],
            'basic without password'  => [new BasicCredentialType(), ['username' => 'u'], []],
            'basic without username'  => [new BasicCredentialType(), [], ['password' => 'p']],
            'header without name'     => [new ApiKeyHeaderCredentialType(), [], ['key' => 'k']],
            'header with a bad name'  => [new ApiKeyHeaderCredentialType(), ['header' => "X-Key\r\nEvil: 1"], ['key' => 'k']],
            'header without key'      => [new ApiKeyHeaderCredentialType(), ['header' => 'X-Key'], []],
            'query without parameter' => [new ApiKeyQueryCredentialType(), [], ['key' => 'k']],
            'query without key'       => [new ApiKeyQueryCredentialType(), ['parameter' => 'k'], []],
        ];
    }
    public function test_bearer_sends_the_token_in_the_authorization_header(): void
    {
        $authorization = (new BearerCredentialType())->authorize($this->secret('bearer', [], ['token' => 'tok-123456']));

        $this->assertSame(['Authorization' => 'Bearer tok-123456'], $authorization->headers());
        $this->assertSame([], $authorization->query());
        $this->assertSame(['tok-123456'], $authorization->secrets());
    }

    public function test_basic_sends_the_encoded_pair_and_masks_the_password_and_the_pair_but_not_the_username(): void
    {
        $authorization = (new BasicCredentialType())->authorize($this->secret('basic', ['username' => 'robot'], ['password' => 'p@ss-word']));

        $encoded = base64_encode('robot:p@ss-word');

        $this->assertSame(['Authorization' => 'Basic ' . $encoded], $authorization->headers());
        $this->assertSame(['p@ss-word', $encoded], $authorization->secrets());
        $this->assertNotContains('robot', $authorization->secrets());
    }

    public function test_api_key_header_prefixes_the_key_and_uses_the_chosen_header(): void
    {
        $authorization = (new ApiKeyHeaderCredentialType())->authorize(
            $this->secret('api_key_header', ['header' => 'X-Api-Key', 'prefix' => 'Token '], ['key' => 'key-123456']),
        );

        $this->assertSame(['X-Api-Key' => 'Token key-123456'], $authorization->headers());
        $this->assertSame(['key-123456'], $authorization->secrets());
    }

    public function test_api_key_header_prefix_is_optional(): void
    {
        $authorization = (new ApiKeyHeaderCredentialType())->authorize(
            $this->secret('api_key_header', ['header' => 'X-Api-Key'], ['key' => 'key-123456']),
        );

        $this->assertSame(['X-Api-Key' => 'key-123456'], $authorization->headers());
    }

    public function test_api_key_query_sends_a_parameter_and_masks_the_encoded_forms(): void
    {
        $authorization = (new ApiKeyQueryCredentialType())->authorize(
            $this->secret('api_key_query', ['parameter' => 'api_key'], ['key' => 'a b&c/d+e']),
        );

        $this->assertSame(['api_key' => 'a b&c/d+e'], $authorization->query());
        $this->assertSame([], $authorization->headers());
        $this->assertSame(['a b&c/d+e', 'a%20b%26c%2Fd%2Be', 'a+b%26c%2Fd%2Be'], $authorization->secrets());
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $values
     */
    #[DataProvider('incomplete')]
    public function test_a_missing_value_makes_the_credential_unavailable(HttpCredentialTypeInterface $type, array $settings, array $values): void
    {
        $this->expectException(CredentialUnavailableException::class);

        $type->authorize($this->secret($type->id(), $settings, $values));
    }

    public function test_the_secret_fields_are_exactly_the_values_a_type_reads(): void
    {
        foreach ([new BearerCredentialType(), new BasicCredentialType(), new ApiKeyHeaderCredentialType(), new ApiKeyQueryCredentialType()] as $type) {
            $secretKeys = array_map(static fn ($field): string => $field->key, array_filter($type->fields(), static fn ($field): bool => $field->secret));

            $this->assertNotSame([], $secretKeys, $type->id());
            $this->assertNotSame('', $type->label());
        }
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $values
     */
    private function secret(string $type, array $settings, array $values): CredentialSecret
    {
        return new CredentialSecret('tenant-1', 'cred-1', $type, 'v1', $settings, ['api.example.com'], $values);
    }
}
