<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use App\Domains\Solution\DTO\ConnectorDiscovery;
use App\Domains\Solution\DTO\ExtensionDiscovery;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Connectors\Echo\EchoConnectorServiceProvider;
use Tests\Fixtures\Solution\FeedbackServiceProvider;
use Tests\Support\FakeVendor;

final class ConnectorManifestValidatorTest extends TestCase
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

    public function test_a_valid_connector_is_in_the_catalog(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer(), '1.3.0');

        $discovery = $this->discover();

        $this->assertTrue($discovery->isValid());
        $this->assertCount(1, $discovery->valid);

        $connector = $discovery->valid[0];
        $this->assertSame('echo', $connector->id);
        $this->assertSame('Echo', $connector->name);
        $this->assertSame('acme/fapost-connector-echo', $connector->package);
        $this->assertSame('1.3.0', $connector->version);
        $this->assertSame(EchoConnectorServiceProvider::class, $connector->provider);
        $this->assertSame(['echo'], $connector->channels);
        $this->assertStringEndsWith('/acme/fapost-connector-echo/composer.json', (string) $connector->composerPath);
        $this->assertSame($connector->composerPath, $discovery->watchedFiles[0]);
    }

    public function test_a_connector_does_not_make_the_solution_catalog_invalid_or_noisy(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer());

        $solutions = $this->discoverAll()->solutions;

        $this->assertTrue($solutions->isValid());
        $this->assertSame([], $solutions->valid);
        $this->assertSame([], $solutions->warnings);
    }

    public function test_a_foundation_violation_names_the_package_and_the_field_and_keeps_the_package_out(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer(['channels' => null]));

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertSame(['acme/fapost-connector-echo: extra.fapost.channels: required'], $this->lines($discovery));
    }

    public function test_a_provider_class_that_does_not_exist_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer(['provider' => 'Acme\\Missing\\Provider']));

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertStringContainsString('acme/fapost-connector-echo: extra.fapost.provider: class Acme\\Missing\\Provider does not exist', $this->lines($discovery)[0]);
    }

    public function test_a_provider_that_does_not_extend_the_connector_base_class_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer(['provider' => FeedbackServiceProvider::class]));

        $discovery = $this->discover();

        $this->assertSame([], $discovery->valid);
        $this->assertStringContainsString(
            'extra.fapost.provider: ' . FeedbackServiceProvider::class . ' must extend Fapost\\Foundation\\Lifecycle\\AbstractConnectorServiceProvider',
            $this->lines($discovery)[0],
        );
    }

    public function test_a_composer_json_that_is_not_json_is_a_violation(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer());
        $this->vendor->writePackageFile('acme/fapost-connector-echo', '{ not json');

        $this->assertSame(['acme/fapost-connector-echo: composer.json: is not valid JSON'], $this->lines($this->discover()));
    }

    public function test_two_connectors_with_one_id_are_both_left_out_and_both_named(): void
    {
        $this->vendor
            ->package(FakeVendor::connectorComposer())
            ->package(FakeVendor::connectorComposer(['channels' => ['echo_two']], ['name' => 'acme/fapost-connector-echo-fork']))
            ->package(FakeVendor::connectorComposer(['id' => 'other', 'channels' => ['other']], ['name' => 'acme/fapost-connector-other']));

        $discovery = $this->discover();

        $this->assertSame(['other'], array_map(static fn ($connector): string => $connector->id, $discovery->valid));
        $this->assertSame(
            ['acme/fapost-connector-echo, acme/fapost-connector-echo-fork: extra.fapost.id: "echo" is declared by both packages'],
            $this->lines($discovery),
        );
    }

    public function test_a_channel_type_claimed_by_two_connectors_leaves_both_out(): void
    {
        $this->vendor
            ->package(FakeVendor::connectorComposer())
            ->package(FakeVendor::connectorComposer(['id' => 'echo_two'], ['name' => 'acme/fapost-connector-echo-two']))
            ->package(FakeVendor::connectorComposer(['id' => 'other', 'channels' => ['other']], ['name' => 'acme/fapost-connector-other']));

        $discovery = $this->discover();

        $this->assertSame(['other'], array_map(static fn ($connector): string => $connector->id, $discovery->valid));
        $this->assertSame(
            ['acme/fapost-connector-echo, acme/fapost-connector-echo-two: extra.fapost.channels: "echo" is declared by both packages'],
            $this->lines($discovery),
        );
    }

    public function test_a_solution_and_a_connector_with_one_id_are_both_invalid(): void
    {
        $this->vendor
            ->package(FakeVendor::solutionComposer(['id' => 'echo', 'actions' => null]))
            ->package(FakeVendor::connectorComposer())
            ->package(FakeVendor::connectorComposer(['id' => 'other', 'channels' => ['other']], ['name' => 'acme/fapost-connector-other']));

        $discovery = $this->discoverAll();

        $this->assertSame([], $discovery->solutions->valid);
        $this->assertSame(['other'], array_map(static fn ($connector): string => $connector->id, $discovery->connectors->valid));
        $this->assertFalse($discovery->isValid());
        $this->assertSame(
            ['acme/fapost-connector-echo, acme/fapost-feedback: extra.fapost.id: "echo" is declared by both packages'],
            array_map(static fn ($violation): string => $violation->line(), $discovery->violations()),
        );
    }

    public function test_a_connector_is_invalid_when_an_invalid_solution_claims_its_id(): void
    {
        $this->vendor
            ->package(FakeVendor::solutionComposer(['id' => 'echo', 'actions' => null]))
            ->package(FakeVendor::solutionComposer(['id' => 'echo', 'actions' => null], ['name' => 'acme/fapost-feedback-fork']))
            ->package(FakeVendor::connectorComposer());

        $discovery = $this->discoverAll();

        $this->assertSame([], $discovery->solutions->valid);
        $this->assertSame([], $discovery->connectors->valid);
        $this->assertContains(
            'acme/fapost-connector-echo, acme/fapost-feedback, acme/fapost-feedback-fork: extra.fapost.id: "echo" is declared by all 3 packages',
            array_map(static fn ($violation): string => $violation->line(), $discovery->violations()),
        );
    }

    private function discoverAll(): ExtensionDiscovery
    {
        $this->vendor->write();

        return (new SolutionDiscoverer(
            new InstalledPackagesReader($this->vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        ))->discoverAll();
    }

    private function discover(): ConnectorDiscovery
    {
        return $this->discoverAll()->connectors;
    }

    /**
     * @return list<string>
     */
    private function lines(ConnectorDiscovery $discovery): array
    {
        return array_map(static fn ($violation): string => $violation->line(), $discovery->violations);
    }
}
