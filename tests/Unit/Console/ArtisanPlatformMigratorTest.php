<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Platform\ArtisanPlatformMigrator;
use ArrayObject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The three steps `platform:update` migrates with, one call each: the landlord, the tenants, then one `solutions:migrate`
 * pass per Solution, whose failures are told apart by Solution id. The commands are stand-ins that record their calls:
 * nothing here touches a database.
 */
final class ArtisanPlatformMigratorTest extends TestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $calls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = new ArrayObject();
    }

    public function test_the_landlord_is_migrated_with_force(): void
    {
        $this->fakeSteps();

        $this->assertTrue((new ArtisanPlatformMigrator())->migrateLandlord(new BufferedOutput()));
        $this->assertSame(['migrate:landlord:force'], $this->calls->getArrayCopy());
    }

    public function test_a_failing_landlord_migration_is_reported(): void
    {
        $this->fakeSteps(failing: 'migrate');

        $this->assertFalse((new ArtisanPlatformMigrator())->migrateLandlord(new BufferedOutput()));
    }

    public function test_the_tenants_are_migrated_and_a_failure_is_reported(): void
    {
        $this->fakeSteps(failing: 'ops:tenants-migrate');

        $this->assertFalse((new ArtisanPlatformMigrator())->migrateTenants(new BufferedOutput()));
        $this->assertSame(['ops:tenants-migrate'], $this->calls->getArrayCopy());
    }

    public function test_each_solution_is_migrated_on_its_own_and_only_the_failing_ones_are_returned(): void
    {
        $this->fakeSteps(failingSolution: 'beta');

        $failed = (new ArtisanPlatformMigrator())->migrateSolutions(['alpha', 'beta', 'gamma'], new BufferedOutput());

        $this->assertSame(['beta'], $failed);
        $this->assertSame(['solutions:migrate:alpha', 'solutions:migrate:beta', 'solutions:migrate:gamma'], $this->calls->getArrayCopy());
    }

    public function test_no_solutions_means_no_pass(): void
    {
        $this->fakeSteps();

        $this->assertSame([], (new ArtisanPlatformMigrator())->migrateSolutions([], new BufferedOutput()));
        $this->assertSame([], $this->calls->getArrayCopy());
    }

    private function fakeSteps(?string $failing = null, ?string $failingSolution = null): void
    {
        foreach (['migrate', 'ops:tenants-migrate', 'solutions:migrate'] as $name) {
            Artisan::registerCommand(new class ($name, $this->calls, $name === $failing, $failingSolution) extends Command {
                /**
                 * @param  ArrayObject<int, string>  $calls
                 */
                public function __construct(string $name, private readonly ArrayObject $calls, private readonly bool $fails, private readonly ?string $failingSolution)
                {
                    $this->signature = match ($name) {
                        'migrate'           => 'migrate {--database=} {--force}',
                        'solutions:migrate' => 'solutions:migrate {--solution=}',
                        default             => $name,
                    };

                    parent::__construct();
                }

                public function handle(): int
                {
                    $this->calls->append(match ($this->getName()) {
                        'migrate'           => 'migrate:' . $this->option('database') . ($this->option('force') ? ':force' : ''),
                        'solutions:migrate' => 'solutions:migrate:' . $this->option('solution'),
                        default             => (string) $this->getName(),
                    });

                    $fails = $this->fails || ('solutions:migrate' === $this->getName() && $this->failingSolution === $this->option('solution'));

                    return $fails ? Command::FAILURE : Command::SUCCESS;
                }
            });
        }
    }
}
