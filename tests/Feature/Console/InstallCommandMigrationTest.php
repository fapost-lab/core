<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Platform\InstallCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class InstallCommandMigrationTest extends TestCase
{
    public function test_platform_migrations_run_on_landlord_without_a_path(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', Mockery::on(fn (array $arguments): bool => 'landlord' === $arguments['--database']
                && true === $arguments['--force']
                && ! array_key_exists('--path', $arguments)))
            ->andReturn(InstallCommand::SUCCESS);

        $this->assertTrue($this->runMigrateStep());
    }

    public function test_a_failed_migration_stops_the_installation(): void
    {
        Artisan::shouldReceive('call')->once()->with('migrate', Mockery::any())->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('boom');

        $this->assertFalse($this->runMigrateStep());
    }

    private function runMigrateStep(): bool
    {
        $output  = new OutputStyle(new ArrayInput([]), new BufferedOutput());
        $command = $this->app->make(InstallCommand::class);
        $command->setLaravel($this->app);
        $command->setOutput($output);

        $components = new ReflectionMethod($command, 'migrate');
        $property   = new ReflectionProperty($command, 'components');
        $property->setValue($command, new Factory($output));

        return (bool) $components->invoke($command);
    }
}
