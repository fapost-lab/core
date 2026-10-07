<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CountableModels;
use Tests\TestCase;

/**
 * Creating a model whose count a tenant limit caps must happen only in its creator service, or
 * the limit is bypassed. PHPat sees only `new X` (see `Tests\Architecture\CountableModelCreationTest`),
 * so this scans `app/` for the rest: static creates, creates through a relation, and `replicate()`.
 */
final class CountableModelCreationTest extends TestCase
{
    private const string CREATION_CALLS = '(?:create|forceCreate|insert|insertOrIgnore|firstOrCreate|updateOrCreate|upsert|make)';

    private const string RELATION_CALLS = '(?:create|createMany|forceCreate|firstOrCreate|updateOrCreate|save|saveMany|make)';

    /**
     * @return array<class-string, array{class-string, list<class-string>, string}>
     */
    public static function countableModels(): array
    {
        $cases = [];

        foreach (CountableModels::models() as $model => $info) {
            $cases[$model] = [$model, $info['creators'], $info['relation']];
        }

        return $cases;
    }

    /**
     * @param  class-string        $model
     * @param  list<class-string>  $creators
     */
    #[DataProvider('countableModels')]
    public function test_app_does_not_create_countable_model_outside_its_creator(string $model, array $creators, string $relation): void
    {
        $violations = $this->scan(base_path('app'), $model, $creators, $relation);

        $this->assertSame(
            [],
            $violations,
            sprintf('%s must be created only by %s, which check the tenant limit.', $model, implode(', ', $creators)),
        );
    }

    public function test_scanner_flags_creation_patterns(): void
    {
        foreach ([
            'Assistant::create([]);',
            'Assistant::query()->create([]);',
            'Assistant::query()->forceCreate([]);',
            'Assistant::insert([]);',
            'Assistant::firstOrCreate([]);',
            'Assistant::make([]);',
            'Assistant::withoutGlobalScopes()->create([]);',
            'Assistant::query()->where(fn ($q) => $q->where("a", (int) $x))->create([]);',
            'Assistant::on(config("a"))->updateOrCreate(["a" => foo(1)], []);',
            '$user->assistants()->create([]);',
            '$tenant->assistants()->createMany([]);',
            '$x->assistants()->save($a);',
            '$x->assistants()->make([]);',
            '$copy = Assistant::find(1)->replicate();',
            '/** @var Assistant $a */ $a->replicate(["id"]);',
        ] as $code) {
            $this->assertNotSame([], $this->violationsIn($code, 'Assistant', 'assistants'), $code);
        }

        foreach ([
            'Assistant::query()->count();',
            'Assistant::factory()->create();',
            '$assistant->save();',
            '$user->assistants()->syncWithoutDetaching([1]);',
            '$user->assistants()->whereKey(1)->exists();',
            '$other->replicate();',
        ] as $code) {
            $this->assertSame([], $this->violationsIn($code, 'Assistant', 'assistants'), $code);
        }
    }

    /**
     * @param  class-string        $model
     * @param  list<class-string>  $creators
     *
     * @return list<string> files that create the model
     */
    private function scan(string $dir, string $model, array $creators, string $relation): array
    {
        $short      = $this->shortName($model);
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();
            $code = (string) file_get_contents($path);

            foreach ([...$creators, $model] as $allowed) {
                if ($this->declares($code, $allowed)) {
                    continue 2;
                }
            }

            if ([] !== $this->violationsIn($code, $short, $relation)) {
                $violations[] = mb_substr($path, mb_strlen(base_path()) + 1);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function violationsIn(string $code, string $short, string $relation): array
    {
        $parens = '(?<p>\((?:[^()]++|(?&p))*+\))';
        $found  = [];

        preg_match_all(
            '/\b' . $short . '::(?:' . self::CREATION_CALLS . '\s*\(|(?!factory\b)\w+\s*' . $parens . '(?:\s*->\s*\w+\s*(?&p))*?\s*->\s*' . self::CREATION_CALLS . '\s*\()/',
            $code,
            $static,
        );
        $found = [...$found, ...$static[0]];

        preg_match_all('/->\s*' . $relation . '\s*\(\s*\)\s*->\s*' . self::RELATION_CALLS . '\s*\(/', $code, $viaRelation);
        $found = [...$found, ...$viaRelation[0]];

        // A replicated record is a new record. Only files that deal with the model are suspect.
        if (str_contains($code, $short) && 1 === preg_match('/->\s*replicate\s*\(/', $code)) {
            $found[] = '->replicate(';
        }

        return $found;
    }

    private function declares(string $code, string $class): bool
    {
        return str_contains($code, 'namespace ' . $this->namespaceOf($class) . ';')
            && 1 === preg_match('/\b(?:final\s+)?(?:readonly\s+)?class\s+' . $this->shortName($class) . '\b/', $code);
    }

    private function namespaceOf(string $class): string
    {
        return mb_substr($class, 0, (int) mb_strrpos($class, '\\'));
    }

    private function shortName(string $class): string
    {
        return mb_substr($class, (int) mb_strrpos($class, '\\') + 1);
    }
}
