<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Solution\Contracts\InstalledSolutionCatalogInterface;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Tests\Fixtures\Solution\FeedbackServiceProvider;
use Tests\Support\FakeVendor;
use Tests\TestCase;

final class SolutionServiceProviderTest extends TestCase
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

    public function test_core_boots_the_provider_of_a_valid_solution(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer());
        $this->app->instance(InstalledSolutions::class, $this->installedSolutions());

        $this->assertNull($this->app->getProvider(FeedbackServiceProvider::class));

        (new SolutionServiceProvider($this->app))->register();

        $this->assertInstanceOf(FeedbackServiceProvider::class, $this->app->getProvider(FeedbackServiceProvider::class));
    }

    public function test_core_does_not_boot_the_provider_of_an_invalid_solution(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['id' => 'x']));
        $this->app->instance(InstalledSolutions::class, $this->installedSolutions());

        (new SolutionServiceProvider($this->app))->register();

        $this->assertNull($this->app->getProvider(FeedbackServiceProvider::class));
    }

    public function test_the_foundation_catalog_is_answered_from_the_installed_solutions(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(), '1.2.0');
        $this->app->instance(InstalledSolutions::class, $this->installedSolutions());

        $catalog = $this->app->make(InstalledSolutionCatalogInterface::class);

        $this->assertCount(1, $catalog->all());
        $this->assertSame('feedback', $catalog->all()[0]->id);
        $this->assertSame('Feedback', $catalog->all()[0]->name);
        $this->assertSame('1.2.0', $catalog->find('feedback')?->version);
        $this->assertTrue($catalog->has('feedback'));
        $this->assertFalse($catalog->has('hr'));
        $this->assertNull($catalog->find('hr'));
    }

    public function test_the_application_boots_with_no_solutions_installed(): void
    {
        $this->assertSame([], $this->app->make(InstalledSolutionCatalogInterface::class)->all());
    }

    private function installedSolutions(): InstalledSolutions
    {
        $this->vendor->write();

        return new InstalledSolutions(
            new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php'),
            new SolutionDiscoverer(
                new InstalledPackagesReader($this->vendor->path),
                new SolutionManifestValidator(new ManifestParser()),
            ),
        );
    }
}
