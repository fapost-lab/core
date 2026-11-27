<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use App\Domains\Solution\DTO\InstalledSolution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstalledSolutionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function missingKeys(): iterable
    {
        yield 'migrations' => ['migrations'];
        yield 'latestMigration' => ['latestMigration'];
        yield 'migrationsDigest' => ['migrationsDigest'];
        yield 'triggers' => ['triggers'];
        yield 'commandTypes' => ['commandTypes'];
        yield 'commands' => ['commands'];
    }

    /**
     * @return iterable<string, array{0: string|null, 1: bool}>
     */
    public static function schemaStates(): iterable
    {
        yield 'nothing applied' => [null, false];
        yield 'the same files' => [InstalledSolution::digestOf(['2026_11_16_000001_create_notes', '2026_11_16_000002_add_index']), true];
        yield 'an older set (the package gained a file)' => [InstalledSolution::digestOf(['2026_11_16_000001_create_notes']), false];
        yield 'a file that sorts before the applied ones was added' => [InstalledSolution::digestOf(['2026_11_16_000002_add_index']), false];
        yield 'a larger set (the package moved back to fewer files)' => [InstalledSolution::digestOf(['2026_11_16_000001_create_notes', '2026_11_16_000002_add_index', '2026_11_17_000001_later']), false];
    }
    public function test_a_solution_without_migrations_is_always_current(): void
    {
        $solution = $this->solution();

        $this->assertTrue($solution->isSchemaCurrent(null));
        $this->assertTrue($solution->isSchemaCurrent(InstalledSolution::digestOf(['anything'])));
    }

    #[DataProvider('schemaStates')]
    public function test_a_schema_is_current_only_when_it_was_last_brought_up_to_exactly_the_files_the_package_ships(?string $applied, bool $current): void
    {
        $solution = $this->solution(migrations: 'database/migrations', latestMigration: '2026_11_16_000002_add_index', migrationsDigest: InstalledSolution::digestOf(['2026_11_16_000001_create_notes', '2026_11_16_000002_add_index']));

        $this->assertSame($current, $solution->isSchemaCurrent($applied));
    }

    public function test_the_digest_does_not_depend_on_the_order_of_the_names_and_differs_with_the_set(): void
    {
        $this->assertSame(InstalledSolution::digestOf(['b', 'a']), InstalledSolution::digestOf(['a', 'b']));
        $this->assertSame(hash('sha256', "a\nb"), InstalledSolution::digestOf(['b', 'a']));
        $this->assertNotSame(InstalledSolution::digestOf(['a']), InstalledSolution::digestOf(['a', 'b']));
    }

    public function test_the_migrations_path_is_the_declared_directory_in_the_package_of_the_composer_file(): void
    {
        $solution = $this->solution(composerPath: '/vendor/acme/fapost-feedback/composer.json', migrations: 'database/migrations');

        $this->assertSame('/vendor/acme/fapost-feedback/database/migrations', $solution->migrationsPath());
    }

    public function test_there_is_no_migrations_path_without_a_declaration_or_a_known_package_directory(): void
    {
        $this->assertNull($this->solution(composerPath: '/vendor/acme/composer.json')->migrationsPath());
        $this->assertNull($this->solution(composerPath: null, migrations: 'database/migrations')->migrationsPath());
    }

    public function test_it_survives_the_compiled_catalog(): void
    {
        $solution = $this->solution(composerPath: '/vendor/acme/fapost-feedback/composer.json', migrations: 'database/migrations', latestMigration: '2026_11_16_000002_add_index', migrationsDigest: InstalledSolution::digestOf(['2026_11_16_000002_add_index']));

        $this->assertEquals($solution, InstalledSolution::fromArray($solution->toArray()));
    }

    public function test_a_solution_without_migrations_survives_the_compiled_catalog_with_nulls(): void
    {
        $restored = InstalledSolution::fromArray($this->solution()->toArray());

        $this->assertNull($restored->migrations);
        $this->assertNull($restored->latestMigration);
    }

    public function test_command_types_and_commands_survive_the_compiled_catalog(): void
    {
        $restored = InstalledSolution::fromArray($this->solution()->toArray());

        $this->assertSame(['feedback.thank'], $restored->commandTypes);
        $this->assertSame(['/feedback'], $restored->commands);
    }

    #[DataProvider('missingKeys')]
    public function test_a_cache_written_before_migrations_is_refused_as_stale(string $missing): void
    {
        $data = $this->solution()->toArray();
        unset($data[$missing]);

        $this->expectException(InvalidArgumentException::class);

        InstalledSolution::fromArray($data);
    }

    private function solution(?string $composerPath = '/vendor/acme/fapost-feedback/composer.json', ?string $migrations = null, ?string $latestMigration = null, ?string $migrationsDigest = null): InstalledSolution
    {
        return new InstalledSolution(
            id: 'feedback',
            name: 'Feedback',
            description: null,
            package: 'acme/fapost-feedback',
            version: '1.0.0',
            foundationConstraint: '^0.13',
            provider: 'Acme\\Provider',
            actions: ['feedback.submit'],
            composerPath: $composerPath,
            migrations: $migrations,
            latestMigration: $latestMigration,
            migrationsDigest: $migrationsDigest,
            triggers: ['feedback.submitted'],
            commandTypes: ['feedback.thank'],
            commands: ['/feedback'],
        );
    }
}
