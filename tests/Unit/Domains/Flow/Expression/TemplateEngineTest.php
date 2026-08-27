<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Expression;

use App\Domains\Flow\Expression\Engines\TemplateEngine;
use Fapost\Foundation\Flow\Contracts\ExpressionSyntaxException;
use Fapost\Foundation\Flow\Contracts\ScopedStateReaderInterface;
use Fapost\Foundation\Flow\DTO\ExpressionContext;
use Tests\TestCase;

final class TemplateEngineTest extends TestCase
{
    public function test_id_and_version(): void
    {
        $engine = new TemplateEngine();

        $this->assertSame('template', $engine->id());
        $this->assertSame(1, $engine->version());
    }

    public function test_evaluate_substitutes_known_paths(): void
    {
        $engine = new TemplateEngine();

        $context = $this->context([
            'contact.first_name' => 'Иван',
            'flow.attempts'      => 3,
        ]);

        $this->assertSame(
            'Hi Иван (attempt 3)',
            $engine->evaluate('Hi {{contact.first_name}} (attempt {{flow.attempts}})', $context),
        );
    }

    public function test_evaluate_unknown_path_substitutes_empty_string(): void
    {
        $engine = new TemplateEngine();

        $this->assertSame(
            'Hi !',
            $engine->evaluate('Hi {{contact.first_name}}!', $this->context([])),
        );
    }

    public function test_evaluate_returns_input_unchanged_when_no_placeholders(): void
    {
        $engine = new TemplateEngine();

        $this->assertSame(
            'just a literal',
            $engine->evaluate('just a literal', $this->context([])),
        );
    }

    public function test_evaluate_stringifies_scalars_arrays_and_null(): void
    {
        $engine = new TemplateEngine();

        $context = $this->context([
            'flow.bool_yes'  => true,
            'flow.bool_no'   => false,
            'flow.empty'     => null,
            'flow.list'      => ['a', 'b'],
            'flow.number'    => 42,
        ]);

        $this->assertSame('true|false||["a","b"]|42', $engine->evaluate(
            '{{flow.bool_yes}}|{{flow.bool_no}}|{{flow.empty}}|{{flow.list}}|{{flow.number}}',
            $context,
        ));
    }

    public function test_validate_passes_for_valid_namespaced_paths(): void
    {
        $engine = new TemplateEngine();

        $engine->validate('Hi {{contact.first_name}} from {{module.hr.department}}');
        $this->expectNotToPerformAssertions();
    }

    public function test_validate_throws_for_unknown_namespace(): void
    {
        $engine = new TemplateEngine();

        $this->expectException(ExpressionSyntaxException::class);
        $this->expectExceptionMessageMatches("/Invalid placeholder path 'foo\\.bar'/");

        $engine->validate('Hello {{foo.bar}}');
    }

    public function test_validate_throws_for_path_without_segments(): void
    {
        $engine = new TemplateEngine();

        $this->expectException(ExpressionSyntaxException::class);

        $engine->validate('Hi {{contact}}');
    }

    public function test_extract_references_returns_unique_paths(): void
    {
        $engine = new TemplateEngine();

        $refs = $engine->extractReferences('{{contact.first_name}} and {{contact.first_name}} also {{flow.attempts}}');

        sort($refs);
        $this->assertSame(['contact.first_name', 'flow.attempts'], $refs);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function context(array $values): ExpressionContext
    {
        $reader = new class ($values) implements ScopedStateReaderInterface {
            /**
             * @param  array<string, mixed>  $values
             */
            public function __construct(private readonly array $values) {}

            public function read(string $path): mixed
            {
                return $this->values[$path] ?? null;
            }
        };

        return new ExpressionContext('tenant-1', 'contact-1', 'session-1', $reader);
    }
}
