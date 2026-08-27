<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class NgrokHttpScriptTest extends TestCase
{
    public function test_builds_expected_ngrok_command_for_bare_local_domain(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-ngrok-' . bin2hex(random_bytes(8));

        mkdir($temporaryDirectory, 0777, true);

        $fakeNgrokPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'fake-ngrok.sh';

        file_put_contents(
            $fakeNgrokPath,
            <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$@"
BASH,
        );
        chmod($fakeNgrokPath, 0755);

        $process = new Process(
            [$this->repositoryPath('tools/ngrok-http'), 'fapost-core'],
            env: [
                'NGROK_BIN' => $fakeNgrokPath,
            ],
        );

        $process->mustRun();

        self::assertSame(
            implode("\n", [
                'http',
                '--region=eu',
                '--domain=mobiman.eu.ngrok.io',
                '--host-header=fapost-core.test',
                'fapost-core.test:80',
                '',
            ]),
            $process->getOutput(),
        );
    }

    public function test_preserves_fully_qualified_local_domain(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-ngrok-' . bin2hex(random_bytes(8));

        mkdir($temporaryDirectory, 0777, true);

        $fakeNgrokPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'fake-ngrok.sh';

        file_put_contents(
            $fakeNgrokPath,
            <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$@"
BASH,
        );
        chmod($fakeNgrokPath, 0755);

        $process = new Process(
            [$this->repositoryPath('tools/ngrok-http'), 'demo.test'],
            env: [
                'NGROK_BIN' => $fakeNgrokPath,
            ],
        );

        $process->mustRun();

        self::assertStringContainsString('--host-header=demo.test', $process->getOutput());
        self::assertStringContainsString('demo.test:80', $process->getOutput());
    }

    public function test_fails_when_local_domain_is_missing(): void
    {
        $process = new Process([$this->repositoryPath('tools/ngrok-http')]);

        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Usage:', $process->getErrorOutput());
    }

    private function repositoryPath(string $path = ''): string
    {
        $repositoryRoot = dirname(__DIR__, 3);

        if ('' === $path) {
            return $repositoryRoot;
        }

        return $repositoryRoot . DIRECTORY_SEPARATOR . $path;
    }
}
