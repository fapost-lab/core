<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\ActionHandlerFactoryInterface;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use ArrayObject;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\FeedbackProbe;
use Tests\Fixtures\Solution\FeedbackServiceProvider;
use Tests\Support\FakeVendor;
use Tests\TestCase;

/**
 * Runs the real boot sequence on a fresh application: the booting callback of the Solution provider,
 * every provider's boot(), then the freeze of the registry. The shared test application is already booted,
 * so the order cannot be seen on it.
 */
final class SolutionRegistrationBootTest extends TestCase
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

    public function test_extensions_are_registered_before_the_solution_boots_and_before_the_registry_freezes(): void
    {
        $log      = new ArrayObject();
        $app      = $this->freshApplication(FakeVendor::solutionComposer(), $log);
        $registry = $app->make(ActionHandlerRegistry::class);

        $app->booted(function () use ($log, $registry): void {
            $log->append('registry frozen with ' . implode(',', $registry->ids()));
            $registry->freeze();
        });

        $app->boot();

        $this->assertSame(
            ['registerExtensions', 'bootSolution', 'registry frozen with feedback.submit,feedback.score'],
            $log->getArrayCopy(),
        );
        $this->assertSame('feedback', $registry->ownerOf('feedback.submit'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('frozen');
        $registry->register(FeedbackScoreAction::class, 'feedback');
    }

    public function test_scoped_services_of_the_boot_time_handlers_do_not_outlive_registration(): void
    {
        $created = 0;
        $app     = $this->freshApplication(FakeVendor::solutionComposer(), new ArrayObject());
        $app->scoped(FeedbackProbe::class, static function () use (&$created): FeedbackProbe {
            ++$created;

            return new FeedbackProbe();
        });

        $app->boot();
        $duringRegistration = $created;

        $this->assertGreaterThan(0, $duringRegistration, 'The throwaway handler took the scoped probe.');

        $app->make(FeedbackProbe::class);

        $this->assertSame($duringRegistration + 1, $created, 'The request builds its own probe; the boot-time one was dropped.');
    }

    public function test_an_action_the_manifest_does_not_declare_stops_the_boot(): void
    {
        $app = $this->freshApplication(FakeVendor::solutionComposer(['actions' => ['feedback.score']]), new ArrayObject());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers action "feedback.submit"');

        $app->boot();
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function freshApplication(array $composer, ArrayObject $log): Application
    {
        $this->vendor->package($composer)->write();

        $app = new Application(base_path());
        $app->instance('config', new Repository(['solutions' => ['freeze_console_registry' => false]]));
        $app->instance(InstalledSolutions::class, new InstalledSolutions(
            new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php'),
            new SolutionDiscoverer(
                new InstalledPackagesReader($this->vendor->path),
                new SolutionManifestValidator(new ManifestParser()),
            ),
        ));
        $app->instance(FeedbackServiceProvider::LOG, $log);
        $app->singleton(ActionHandlerFactoryInterface::class, ContainerActionHandlerFactory::class);
        $app->singleton(ActionHandlerRegistry::class);
        $app->register(SolutionServiceProvider::class);

        return $app;
    }
}
