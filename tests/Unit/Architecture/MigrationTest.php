<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MigrationTest extends TestCase
{
    public function test_migration_isolation_contract_passes(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('vendor/bin/phpstan'),
            'analyse',
            '--configuration',
            base_path('phpstan.neon'),
            '--debug',
            '--memory-limit=512M',
            '--no-interaction',
        ]);

        $process->setTimeout(300);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
    }
}
