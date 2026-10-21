<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Enums\PlatformSettingFormat;
use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PlatformSettingFieldTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function addresses(): array
    {
        return [
            'empty'      => ['', true],
            'https'      => ['https://example.com/a?b=1', true],
            'upper http' => ['HTTP://example.com', true],
            'ftp'        => ['ftp://example.com', false],
            'javascript' => ['javascript:alert(1)', false],
            'relative'   => ['/solutions', false],
            'bare host'  => ['example.com', false],
            'spaces'     => ['https://exa mple.com', false],
        ];
    }
    public function test_a_configured_value_that_breaks_the_format_falls_back(): void
    {
        $field = $this->urlField();

        $this->assertSame('https://configured.example', $field->definition('https://configured.example')->default);
        $this->assertSame('', $field->definition('')->default);
        $this->assertSame('https://fallback.example', $field->definition('ftp://nope.example')->default);
        $this->assertSame('https://fallback.example', $field->definition(null)->default);
    }

    public function test_a_format_on_a_field_that_is_not_text_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlatformSettingField('a.b', PlatformSettingType::Integer, 'a.b', 5, 'A', 'A.', 'G', min: 0, max: 10, format: PlatformSettingFormat::Url);
    }

    public function test_a_fallback_that_breaks_the_format_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PlatformSettingField('a.b', PlatformSettingType::Text, 'a.b', 'not-a-url', 'A', 'A.', 'G', max: 100, format: PlatformSettingFormat::Url);
    }

    public function test_the_violation_combines_the_definition_and_the_format(): void
    {
        $field      = $this->urlField();
        $definition = $field->definition(null);

        $this->assertNull($field->violation($definition, 'https://example.com'));
        $this->assertNull($field->violation($definition, ''));
        $this->assertSame('must be an http or https address, or empty.', $field->violation($definition, 'example.com'));
        $this->assertNotNull($field->violation($definition, 12));
        $this->assertNotNull($field->violation($definition, 'https://example.com/' . str_repeat('a', 100)));
    }

    #[DataProvider('addresses')]
    public function test_the_url_format(string $value, bool $accepted): void
    {
        $this->assertSame($accepted, null === PlatformSettingFormat::Url->violation($value));
    }

    private function urlField(): PlatformSettingField
    {
        return new PlatformSettingField('a.b', PlatformSettingType::Text, 'a.b', 'https://fallback.example', 'A', 'A.', 'G', max: 60, format: PlatformSettingFormat::Url);
    }
}
