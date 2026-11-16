<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Solution\FeedbackServiceProvider;
use Tests\Fixtures\Solution\NotASolutionProvider;
use Tests\Support\FakeVendor;

final class SolutionManifestValidatorTest extends TestCase
{
    private FakeVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = new FakeVendor();
    }

    protected function tearDown(): void
    {
        $this->vendor->cleanup();

        parent::tearDown();
    }

    public function test_a_valid_solution_is_in_the_catalog(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(), '1.2.0');

        $discovery = $this->discover();

        $this->assertTrue($discovery->isValid());
        $this->assertCount(1, $discovery->valid);

        $solution = $discovery->valid[0];
        $this->assertSame('feedback', $solution->id);
        $this->assertSame('Feedback', $solution->name);
        $this->assertSame('acme/fapost-feedback', $solution->package);
        $this->assertSame('1.2.0', $solution->version);
        $this->assertSame('^0.39', $solution->foundationConstraint);
        $this->assertSame(FeedbackServiceProvider::class, $solution->provider);
        $this->assertSame(['feedback.submit', 'feedback.score'], $solution->actions);
    }

    public function test_a_manifest_violation_names_the_package_and_the_field_and_keeps_the_package_out(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['id' => 'Feedback!']));

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertSame(
            ['acme/fapost-feedback: extra.fapost.id: must match ^[a-z][a-z0-9_]{1,31}$'],
            $this->lines($discovery),
        );
    }

    public function test_every_violation_of_every_package_is_reported(): void
    {
        $this->vendor
            ->package(FakeVendor::solutionComposer(['id' => null, 'name' => null]))
            ->package(['name' => 'acme/other', 'type' => 'fapost-solution', 'require' => ['fapost/foundation' => '^0.13']]);

        $lines = $this->lines($this->discover());

        $this->assertContains('acme/fapost-feedback: extra.fapost.id: required', $lines);
        $this->assertContains('acme/fapost-feedback: extra.fapost.name: required', $lines);
        $this->assertContains('acme/other: extra.fapost: required: an object with the Solution manifest', $lines);
    }

    public function test_a_provider_class_that_does_not_exist_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['provider' => 'Acme\\Missing\\Provider']));

        $lines = $this->lines($this->discover());

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('acme/fapost-feedback: extra.fapost.provider: class Acme\\Missing\\Provider does not exist', $lines[0]);
    }

    public function test_a_provider_that_does_not_extend_the_solution_base_class_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['provider' => NotASolutionProvider::class]));

        $lines = $this->lines($this->discover());

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('extra.fapost.provider: ' . NotASolutionProvider::class . ' must extend Fapost\\Foundation\\Lifecycle\\AbstractSolutionServiceProvider', $lines[0]);
    }

    public function test_a_provider_listed_for_laravel_discovery_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(root: ['extra' => [
            'laravel' => ['providers' => [FeedbackServiceProvider::class]],
            'fapost'  => FakeVendor::solutionComposer()['extra']['fapost'],
        ]]));

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertStringContainsString('extra.laravel.providers: a Solution\'s provider is booted by Core', $this->lines($discovery)[0]);
    }

    public function test_two_packages_with_one_id_are_both_left_out_and_both_named(): void
    {
        $this->vendor
            ->package(FakeVendor::solutionComposer())
            ->package(FakeVendor::solutionComposer(root: ['name' => 'acme/fapost-feedback-fork']))
            ->package(FakeVendor::solutionComposer(['id' => 'survey', 'actions' => null], ['name' => 'acme/fapost-survey']));

        $discovery = $this->discover();

        $this->assertSame(['survey'], array_map(static fn ($solution): string => $solution->id, $discovery->valid));
        $this->assertSame(
            ['acme/fapost-feedback, acme/fapost-feedback-fork: extra.fapost.id: "feedback" is declared by both packages'],
            $this->lines($discovery),
        );
    }

    public function test_a_solution_type_package_without_a_manifest_is_a_violation(): void
    {
        $this->vendor->package(['name' => 'acme/bare', 'type' => 'fapost-solution', 'require' => ['fapost/foundation' => '^0.13']]);

        $this->assertStringContainsString('acme/bare: extra.fapost: required', $this->lines($this->discover())[0]);
    }

    public function test_a_manifest_without_the_package_type_is_a_warning_not_a_violation(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(root: ['type' => 'library']));

        $discovery = $this->discover();

        $this->assertTrue($discovery->isValid());
        $this->assertSame([], $discovery->valid);
        $this->assertCount(1, $discovery->warnings);
        $this->assertStringContainsString('acme/fapost-feedback: carries extra.fapost but its type is "library"', $discovery->warnings[0]);
    }

    public function test_a_composer_json_that_is_not_json_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer());
        $this->vendor->writePackageFile('acme/fapost-feedback', '{ not json');

        $this->assertSame(['acme/fapost-feedback: composer.json: is not valid JSON'], $this->lines($this->discover()));
    }

    public function test_a_solution_that_declares_no_migrations_has_none_and_no_latest(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer());

        $solution = $this->discover()->valid[0];

        $this->assertNull($solution->migrations);
        $this->assertNull($solution->latestMigration);
        $this->assertNull($solution->migrationsDigest);
        $this->assertNull($solution->migrationsPath());
    }

    public function test_the_newest_migration_file_name_is_recorded_without_its_extension(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'database/migrations']));
        $this->migrationsDirectory('2026_11_16_000002_add_notes_index.php', '2026_11_16_000001_create_notes.php', '2026_10_01_000009_older.php', 'README.md', '.gitkeep');

        $discovery = $this->discover();

        $this->assertTrue($discovery->isValid());
        $this->assertSame('database/migrations', $discovery->valid[0]->migrations);
        $this->assertSame('2026_11_16_000002_add_notes_index', $discovery->valid[0]->latestMigration);
        $this->assertSame(
            InstalledSolution::digestOf(['2026_10_01_000009_older', '2026_11_16_000001_create_notes', '2026_11_16_000002_add_notes_index']),
            $discovery->valid[0]->migrationsDigest,
            'Every .php name, not only the newest.',
        );
        $this->assertSame(realpath($this->vendor->path . '/acme/fapost-feedback/database/migrations'), realpath((string) $discovery->valid[0]->migrationsPath()));
    }

    public function test_an_empty_migrations_directory_is_valid_and_has_no_latest(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'database/migrations']));
        $this->migrationsDirectory('README.md');

        $discovery = $this->discover();

        $this->assertTrue($discovery->isValid());
        $this->assertCount(1, $discovery->valid);
        $this->assertNull($discovery->valid[0]->latestMigration);
        $this->assertNull($discovery->valid[0]->migrationsDigest);
    }

    public function test_a_migrations_directory_that_is_missing_is_a_violation_and_keeps_the_package_out(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'database/migrations']));

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertSame(['acme/fapost-feedback: extra.fapost.migrations: "database/migrations" is not a directory in the package'], $this->lines($discovery));
    }

    public function test_a_migrations_path_that_is_a_file_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'database']));
        file_put_contents($this->vendor->path . '/acme/fapost-feedback/database', 'not a directory');

        $this->assertSame(['acme/fapost-feedback: extra.fapost.migrations: "database" is not a directory in the package'], $this->lines($this->discover()));
    }

    public function test_a_migrations_directory_that_leads_out_of_the_package_through_a_symlink_is_a_violation(): void
    {
        $outside = dirname($this->vendor->path) . '/outside/migrations';
        mkdir($outside, 0o777, true);
        touch($outside . '/2026_11_16_000001_create_elsewhere.php');

        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'database']));
        symlink($outside, $this->vendor->path . '/acme/fapost-feedback/database');

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertSame(['acme/fapost-feedback: extra.fapost.migrations: "database" leads out of the package'], $this->lines($discovery));
    }

    public function test_a_symlinked_migrations_directory_that_stays_inside_the_package_is_valid(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'linked']));
        $this->migrationsDirectory('2026_11_16_000001_create_notes.php');
        symlink($this->vendor->path . '/acme/fapost-feedback/database/migrations', $this->vendor->path . '/acme/fapost-feedback/linked');

        $discovery = $this->discover();

        $this->assertTrue($discovery->isValid());
        $this->assertSame('2026_11_16_000001_create_notes', $discovery->valid[0]->latestMigration);
    }

    public function test_a_migrations_problem_does_not_hide_the_other_packages(): void
    {
        $this->vendor
            ->package(FakeVendor::solutionComposer(['migrations' => 'database/migrations']))
            ->package(FakeVendor::solutionComposer(['id' => 'survey', 'actions' => null], ['name' => 'acme/fapost-survey']));

        $discovery = $this->discover();

        $this->assertSame(['survey'], array_map(static fn ($solution): string => $solution->id, $discovery->valid));
        $this->assertCount(1, $discovery->violations);
    }

    private function discover(): SolutionDiscovery
    {
        $this->vendor->write();

        return (new SolutionDiscoverer(
            new InstalledPackagesReader($this->vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        ))->discover();
    }

    /**
     * @return list<string>
     */
    private function lines(SolutionDiscovery $discovery): array
    {
        return array_map(static fn ($violation): string => $violation->line(), $discovery->violations);
    }

    /**
     * Creates the declared migrations directory of the fixture package with empty files of the given names.
     */
    private function migrationsDirectory(string ...$files): void
    {
        $directory = $this->vendor->path . '/acme/fapost-feedback/database/migrations';
        mkdir($directory, 0o777, true);

        foreach ($files as $file) {
            touch($directory . '/' . $file);
        }
    }
}
