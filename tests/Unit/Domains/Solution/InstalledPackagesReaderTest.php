<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use App\Domains\Solution\Services\InstalledPackagesReader;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeVendor;

final class InstalledPackagesReaderTest extends TestCase
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

    public function test_it_picks_out_only_solutions_and_packages_that_claim_to_be_one(): void
    {
        $this->vendor
            ->package(['name' => 'acme/lib', 'type' => 'library', 'require' => ['php' => '^8.4']])
            ->package(FakeVendor::solutionComposer(), '1.2.0')
            ->package(['name' => 'acme/typo', 'type' => 'library', 'extra' => ['fapost' => ['id' => 'typo']]])
            ->write();

        $names = array_map(
            static fn ($package): string => $package->name,
            (new InstalledPackagesReader($this->vendor->path))->candidates(),
        );

        $this->assertSame(['acme/fapost-feedback', 'acme/typo'], $names);
    }

    public function test_it_picks_out_a_connector_by_its_type(): void
    {
        $this->vendor
            ->package(FakeVendor::connectorComposer(root: ['extra' => null]))
            ->package(['name' => 'acme/lib', 'type' => 'library'])
            ->write();

        $packages = (new InstalledPackagesReader($this->vendor->path))->candidates();

        $this->assertCount(1, $packages);
        $this->assertSame('acme/fapost-connector-echo', $packages[0]->name);
        $this->assertSame('fapost-connector', $packages[0]->type);
    }

    public function test_it_reads_the_package_version_type_and_manifest(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(), '1.2.0')->write();

        $package = (new InstalledPackagesReader($this->vendor->path))->candidates()[0];

        $this->assertSame('1.2.0', $package->version);
        $this->assertSame('fapost-solution', $package->type);
        $this->assertSame('feedback', $package->composer['extra']['fapost']['id'] ?? null);
        $this->assertStringEndsWith('/acme/fapost-feedback/composer.json', (string) $package->composerPath);
    }

    public function test_it_reads_the_older_installed_json_that_is_a_bare_list(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer())->write(composerOne: true);

        $this->assertCount(1, (new InstalledPackagesReader($this->vendor->path))->candidates());
    }

    public function test_it_follows_a_symlinked_install_path_and_sees_an_edit_at_once(): void
    {
        $this->vendor->linkedPackage(['name' => 'acme/fapost-feedback', 'type' => 'fapost-solution'], dirname(__DIR__, 3) . '/Fixtures/Solution/packages/feedback')->write();

        $package = (new InstalledPackagesReader($this->vendor->path))->candidates()[0];

        $this->assertSame('dev-main', $package->version);
        $this->assertSame('feedback', $package->composer['extra']['fapost']['id'] ?? null, 'The manifest comes from the linked checkout, not from installed.json.');
    }

    public function test_it_falls_back_to_the_installed_json_entry_when_the_package_ships_no_composer_json(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(), withComposerJson: false)->write();

        $package = (new InstalledPackagesReader($this->vendor->path))->candidates()[0];

        $this->assertNull($package->composerPath);
        $this->assertSame('feedback', $package->composer['extra']['fapost']['id'] ?? null);
    }

    public function test_a_composer_json_that_is_not_json_is_reported_as_unreadable(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $this->vendor->writePackageFile('acme/fapost-feedback', '{ not json');

        $this->assertNull((new InstalledPackagesReader($this->vendor->path))->candidates()[0]->composer);
    }

    public function test_a_missing_or_broken_installed_json_means_no_packages(): void
    {
        $reader = new InstalledPackagesReader($this->vendor->path);
        $this->assertSame([], $reader->candidates());

        file_put_contents($this->vendor->installedJson(), '{ broken');
        $this->assertSame([], $reader->candidates());
    }
}
