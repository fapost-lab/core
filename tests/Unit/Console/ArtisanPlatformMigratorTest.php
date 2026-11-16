<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Platform\ArtisanPlatformMigrator;
use ArrayObject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The three steps `platform:update` migrates with, in order, the landlord failing stops the rest, a later one failing fails the migration. The commands are
 * stand-ins that record their calls: nothing here touches a database.
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

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function failingSteps(): iterable
    {
        yield 'the landlord' => ['migrate', ['migrate:landlord:force']];
        yield 'the tenants' => ['ops:tenants-migrate', ['migrate:landlord:force', 'ops:tenants-migrate']];
        yield 'the solutions tables' => ['solutions:migrate', ['migrate:landlord:force', 'ops:tenants-migrate', 'solutions:migrate']];
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function failingLaterSteps(): iterable
    {
        yield 'the tenants (the Solutions of the tenants that migrated are still brought current)' => ['ops:tenants-migrate', ['migrate:landlord:force', 'ops:tenants-migrate', 'solutions:migrate']];
        yield 'the solutions tables' => ['solutions:migrate', ['migrate:landlord:force', 'ops:tenants-migrate', 'solutions:migrate']];
    }

    public function test_it_migrates_the_landlord_then_the_tenants_then_the_solutions_tables(): void
    {
        $this->fakeSteps();

        $this->assertTrue((new ArtisanPlatformMigrator())->migrate(new BufferedOutput()));

        $this->assertSame(['migrate:landlord:force', 'ops:tenants-migrate', 'solutions:migrate'], $this->calls->getArrayCopy());
    }

    public function test_a_failing_landlord_migration_stops_everything(): void
    {
        $this->fakeSteps('migrate');

        $this->assertFalse((new ArtisanPlatformMigrator())->migrate(new BufferedOutput()));

        $this->assertSame(['migrate:landlord:force'], $this->calls->getArrayCopy());
    }

    /**
     * @param  list<string>  $ran
     */
    #[DataProvider('failingLaterSteps')]
    public function test_a_failing_tenant_or_solutions_step_fails_the_migration_but_the_other_one_still_runs(string $failing, array $ran): void
    {
        $this->fakeSteps($failing);

        $this->assertFalse((new ArtisanPlatformMigrator())->migrate(new BufferedOutput()));

        $this->assertSame($ran, $this->calls->getArrayCopy());
    }

    private function fakeSteps(?string $failing = null): void
    {
        foreach (['migrate', 'ops:tenants-migrate', 'solutions:migrate'] as $name) {
            Artisan::registerCommand(new class ($name, $this->calls, $name === $failing ? Command::FAILURE : Command::SUCCESS) extends Command {
                /**
                 * @param  ArrayObject<int, string>  $calls
                 */
                public function __construct(string $name, private readonly ArrayObject $calls, private readonly int $exit)
                {
                    $this->signature = 'migrate' === $name ? 'migrate {--database=} {--force}' : $name;

                    parent::__construct();
                }

                public function handle(): int
                {
                    $this->calls->append('migrate' === $this->getName() ? 'migrate:' . $this->option('database') . ($this->option('force') ? ':force' : '') : (string) $this->getName());

                    return $this->exit;
                }
            });
        }
    }
}
