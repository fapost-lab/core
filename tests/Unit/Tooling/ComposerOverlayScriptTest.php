<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ComposerOverlayScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-overlay-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);

        file_put_contents($this->root . '/composer.json', json_encode([
            'name'         => 'fapost/core-fixture',
            'repositories' => [['type' => 'vcs', 'url' => 'https://example.test/core.git']],
            'require'      => ['php' => '^8.4', 'acme/core-package' => '^1.0'],
            'require-dev'  => ['acme/dev-package' => '^2.0'],
        ]));
        file_put_contents($this->root . '/composer.lock', '{"packages": []}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root);

        parent::tearDown();
    }

    public function test_without_overlay_file_nothing_is_written(): void
    {
        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString('nothing to overlay', $process->getOutput());
        self::assertFileDoesNotExist($this->root . '/composer.local.json');
        self::assertFileDoesNotExist($this->root . '/composer.local.lock');
    }

    public function test_valid_overlay_prepends_repositories_and_merges_require(): void
    {
        $this->writeOverlay([
            'repositories' => [['type' => 'path', 'url' => 'packages/extra']],
            'require'      => ['acme/extra' => '@dev'],
        ]);

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $local = json_decode((string) file_get_contents($this->root . '/composer.local.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('path', $local['repositories'][0]['type']);
        self::assertSame('vcs', $local['repositories'][1]['type']);
        self::assertSame('@dev', $local['require']['acme/extra']);
        self::assertSame('^1.0', $local['require']['acme/core-package']);
        self::assertSame(
            file_get_contents($this->root . '/composer.lock'),
            file_get_contents($this->root . '/composer.local.lock'),
        );
    }

    public function test_empty_objects_in_core_manifest_stay_objects(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            '{"name": "fapost/core-fixture", "require": {"php": "^8.4"}, "config": {"allow-plugins": {}}}',
        );
        $this->writeOverlay(['require' => ['acme/extra' => '@dev']]);

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString(
            '"allow-plugins": {}',
            (string) file_get_contents($this->root . '/composer.local.json'),
        );
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->writeOverlay(['require' => ['acme/extra' => '@dev'], 'scripts' => []]);

        $process = $this->runScript();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('"scripts"', $process->getErrorOutput());
        self::assertFileDoesNotExist($this->root . '/composer.local.json');
    }

    public function test_overriding_a_core_package_is_rejected(): void
    {
        foreach (['acme/core-package', 'acme/dev-package'] as $package) {
            $this->writeOverlay(['require' => [$package => '*']]);

            $process = $this->runScript();

            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString($package, $process->getErrorOutput());
        }
    }

    public function test_missing_overlay_fails_when_required(): void
    {
        $process = $this->runScript(['--require-overlay']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('--require-overlay', $process->getErrorOutput());
    }

    public function test_package_locked_by_core_is_rejected_case_insensitively(): void
    {
        file_put_contents(
            $this->root . '/composer.lock',
            json_encode(['packages' => [['name' => 'acme/transitive']], 'packages-dev' => [['name' => 'acme/locked-dev']]]),
        );

        foreach (['Acme/Transitive', 'acme/locked-dev'] as $package) {
            $this->writeOverlay(['require' => [$package => '*']]);

            $process = $this->runScript();

            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString($package, $process->getErrorOutput());
        }
    }

    public function test_override_check_ignores_package_name_case(): void
    {
        $this->writeOverlay(['require' => ['ACME/Core-Package' => '*']]);

        self::assertSame(1, $this->runScript()->getExitCode());
    }

    public function test_list_shaped_require_is_rejected(): void
    {
        $this->writeOverlay(['require' => ['acme/extra']]);

        $process = $this->runScript();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('"require"', $process->getErrorOutput());
    }

    public function test_stale_local_lock_is_removed_when_core_lock_is_absent(): void
    {
        unlink($this->root . '/composer.lock');
        file_put_contents($this->root . '/composer.local.lock', 'stale');
        $this->writeOverlay(['require' => ['acme/extra' => '@dev']]);

        self::assertSame(0, $this->runScript()->getExitCode());
        self::assertFileDoesNotExist($this->root . '/composer.local.lock');
    }

    public function test_composer_update_receives_packages_passthrough_and_overlay_environment(): void
    {
        $this->writeOverlay(['require' => ['acme/extra' => '@dev']]);

        $process = $this->runScript(
            ['--require-overlay', '--', '--no-dev', '--', '--no-scripts'],
            withFakeComposer: 0,
        );

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['update', 'acme/extra', '--no-dev', '--no-scripts', 'COMPOSER=composer.local.json'],
            array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => ! str_starts_with($line, '[overlay]') && '' !== $line)),
        );
        self::assertStringContainsString('[overlay] done', $process->getOutput());
    }

    public function test_composer_failure_exit_code_is_propagated(): void
    {
        $this->writeOverlay(['require' => ['acme/extra' => '@dev']]);

        $process = $this->runScript(withFakeComposer: 7);

        self::assertSame(7, $process->getExitCode());
        self::assertStringNotContainsString('[overlay] done', $process->getOutput());
    }

    /**
     * @param  array<string, mixed>  $overlay
     */
    private function writeOverlay(array $overlay): void
    {
        file_put_contents($this->root . '/composer.overlay.json', json_encode($overlay));
    }

    /**
     * Runs the script; without a fake Composer it stays a dry run.
     *
     * @param  list<string>  $arguments
     */
    private function runScript(array $arguments = [], ?int $withFakeComposer = null): Process
    {
        $environment = ['OVERLAY_ROOT' => $this->root];

        if (null === $withFakeComposer) {
            $arguments[] = '--dry-run';
        } else {
            $fake = $this->root . '/fake-composer.sh';
            file_put_contents($fake, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" \"COMPOSER=\$COMPOSER\"\nexit {$withFakeComposer}\n");
            chmod($fake, 0755);
            $environment['OVERLAY_COMPOSER'] = $fake;
        }

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/tools/composer-overlay.php', ...$arguments],
            env: $environment,
        );
        $process->run();

        return $process;
    }
}
