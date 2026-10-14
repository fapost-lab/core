<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeVendor;

final class SolutionCacheTest extends TestCase
{
    private FakeVendor $vendor;

    private SolutionDiscoverer $discoverer;

    private SolutionCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = new FakeVendor();
        $this->vendor->package(FakeVendor::solutionComposer(), '1.0.0')->write();

        $this->discoverer = new SolutionDiscoverer(
            new InstalledPackagesReader($this->vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        );
        $this->cache = new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php');
    }

    protected function tearDown(): void
    {
        $this->vendor->cleanup();

        parent::tearDown();
    }

    public function test_a_written_cache_is_read_back(): void
    {
        $this->assertNull($this->cache->read());

        $this->cache->write($this->discoverer->discover());

        $solutions = $this->cache->read();
        $this->assertNotNull($solutions);
        $this->assertSame('feedback', $solutions[0]->id);
        $this->assertSame(['feedback.submit', 'feedback.score'], $solutions[0]->actions);
        $this->assertSame('1.0.0', $solutions[0]->version);
    }

    public function test_the_cache_is_stale_once_installed_json_changes(): void
    {
        $this->cache->write($this->discoverer->discover());

        $this->vendor->package(FakeVendor::solutionComposer(['id' => 'survey', 'actions' => null], ['name' => 'acme/fapost-survey']))->write();

        $this->assertNull($this->cache->read());
    }

    public function test_the_cache_is_stale_once_a_manifest_is_edited(): void
    {
        $this->cache->write($this->discoverer->discover());

        $this->vendor->writePackageFile('acme/fapost-feedback', json_encode(FakeVendor::solutionComposer(['name' => 'Feedback v2'])));

        $this->assertNull($this->cache->read());
    }

    public function test_the_cache_is_stale_once_the_manifest_of_a_package_it_left_out_is_edited(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['id' => 'survey', 'actions' => null], ['name' => 'acme/fapost-survey', 'type' => 'library']))->write();
        $this->cache->write($this->discoverer->discover());
        $this->assertNotNull($this->cache->read());

        $this->vendor->writePackageFile('acme/fapost-survey', json_encode(FakeVendor::solutionComposer(['id' => 'survey', 'actions' => null], ['name' => 'acme/fapost-survey'])));

        $this->assertNull($this->cache->read());
    }

    public function test_a_damaged_cache_is_ignored(): void
    {
        file_put_contents($this->cache->path(), '<?php return "garbage";');
        $this->assertNull($this->cache->read());

        file_put_contents($this->cache->path(), '<?php this is not php');
        $this->assertNull($this->cache->read());
    }

    public function test_without_a_cache_the_catalog_is_built_from_the_files_and_nothing_is_written(): void
    {
        $solutions = new InstalledSolutions($this->cache, $this->discoverer);

        $this->assertTrue($solutions->has('feedback'));
        $this->assertSame('Feedback', $solutions->find('feedback')?->name);
        $this->assertFalse($solutions->has('survey'));
        $this->assertNull($solutions->find('survey'));
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_stale_cache_does_not_hide_a_solution_that_was_installed_since(): void
    {
        $this->cache->write($this->discoverer->discover());
        $this->vendor->package(FakeVendor::solutionComposer(['id' => 'survey', 'actions' => null], ['name' => 'acme/fapost-survey']))->write();

        $solutions = new InstalledSolutions($this->cache, $this->discoverer);

        $this->assertSame(['feedback', 'survey'], array_map(static fn ($solution): string => $solution->id, $solutions->all()));
    }

    public function test_a_fresh_cache_is_what_the_catalog_serves(): void
    {
        $this->cache->write($this->discoverer->discover());

        // Same size and time as before is impossible to fake on the package files, so prove the source
        // by emptying the vendor tree's package list in a way the stamps cannot see: the cache is trusted.
        $solutions = new InstalledSolutions($this->cache, new SolutionDiscoverer(
            new InstalledPackagesReader('/nonexistent'),
            new SolutionManifestValidator(new ManifestParser()),
        ));

        $this->assertTrue($solutions->has('feedback'));
    }

    public function test_forget_removes_the_file(): void
    {
        $this->cache->write($this->discoverer->discover());

        $this->assertTrue($this->cache->forget());
        $this->assertFileDoesNotExist($this->cache->path());
        $this->assertTrue($this->cache->forget());
    }
}
