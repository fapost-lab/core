<?php

declare(strict_types=1);

namespace Tests\Support;

use Acme\Feedback\RecordFeedbackJob;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Fapost\Testing\PackageAutoloader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installs the fixture Solution `feedback` the way an image holds it: its real package directory (tests/Fixtures/
 * Solution/packages/feedback) linked into a throwaway vendor tree, so the job class in it passes the dispatcher's
 * ownership check. Also the table the fixture job writes to. Call {@see removeFeedback()} in tearDown.
 */
trait InstallsFeedbackSolution
{
    private ?FakeVendor $feedbackVendor = null;

    protected static function feedbackPackage(): string
    {
        return dirname(__DIR__) . '/Fixtures/Solution/packages/feedback';
    }

    /**
     * Lists the real fixture package in a vendor tree and makes its classes loadable. Writes installed.json.
     */
    protected static function linkFeedback(FakeVendor $vendor): void
    {
        PackageAutoloader::register(self::feedbackPackage());

        $vendor->linkedPackage(
            json_decode((string) file_get_contents(self::feedbackPackage() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR),
            self::feedbackPackage(),
            '1.0.0',
        )->write();
    }

    protected function installFeedback(): InstalledSolutions
    {
        $this->removeFeedback();
        $this->feedbackVendor = new FakeVendor();
        self::linkFeedback($this->feedbackVendor);

        $installed = new InstalledSolutions(
            new SolutionCache(dirname($this->feedbackVendor->path) . '/fapost-solutions.php'),
            new SolutionDiscoverer(
                new InstalledPackagesReader($this->feedbackVendor->path),
                new SolutionManifestValidator(new ManifestParser()),
            ),
        );

        $this->app->instance(InstalledSolutions::class, $installed);
        $this->app->forgetInstance(ActiveSolutions::class);

        return $installed;
    }

    protected function removeFeedback(): void
    {
        $this->feedbackVendor?->cleanup();
        $this->feedbackVendor = null;
    }

    /**
     * Creates the table {@see RecordFeedbackJob} writes to in the tenant's schema.
     */
    protected function createFeedbackJobLog(TenantInterface $tenant): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant($tenant, static function (): void {
            Schema::create(RecordFeedbackJob::TABLE, static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('title');
                $table->string('tenant_id');
            });
        });
    }

    /**
     * The titles the job wrote in the tenant's schema; null when the schema has no such table.
     *
     * @return list<string>|null
     */
    protected function feedbackJobLog(TenantInterface $tenant): ?array
    {
        /** @var list<string>|null $titles */
        $titles = $this->app->make(TenantSwitcher::class)->runForTenant(
            $tenant,
            static fn (): ?array => Schema::hasTable(RecordFeedbackJob::TABLE)
                ? DB::table(RecordFeedbackJob::TABLE)->orderBy('title')->pluck('title')->map(strval(...))->all()
                : null,
        );

        return $titles;
    }
}
