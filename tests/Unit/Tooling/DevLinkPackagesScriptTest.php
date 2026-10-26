<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class DevLinkPackagesScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-dev-link-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor', 0777, true);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->root);

        parent::tearDown();
    }

    public function test_without_packages_directory_nothing_is_linked_and_dangling_links_are_reported(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        mkdir($this->root . '/vendor/acme', 0777, true);
        symlink('../../packages/core', $this->root . '/vendor/acme/core');

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('no packages/ directory', $process->getOutput());
        self::assertStringContainsString('vendor/acme/core -> ../../packages/core leads nowhere', $process->getErrorOutput());
        self::assertTrue(is_link($this->root . '/vendor/acme/core'));
    }

    public function test_checkout_whose_tag_satisfies_the_constraint_is_linked(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.2']]);
        $this->checkout('core', 'acme/core', 'v1.4.0');
        $this->installLockedCopy('acme/core');

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('../../packages/core', readlink($this->root . '/vendor/acme/core'));
        self::assertStringContainsString('[dev:link] vendor/acme/core -> ../../packages/core', $process->getOutput());
    }

    public function test_require_dev_counts_as_required(): void
    {
        $this->writeManifest(['require-dev' => ['Acme/Tools' => '~2.1']]);
        $this->checkout('tools', 'acme/tools', '2.1.5');

        $this->runScript();

        self::assertSame('../../packages/tools', readlink($this->root . '/vendor/acme/tools'));
    }

    public function test_checkout_of_an_incompatible_version_is_skipped_and_its_link_removed(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^0.30']]);
        $this->checkout('core', 'acme/core', 'v0.33.0');
        mkdir($this->root . '/vendor/acme', 0777, true);
        symlink('../../packages/core', $this->root . '/vendor/acme/core');

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('skipped vendor/acme/core: the checkout is at v0.33.0, which does not satisfy ^0.30', $process->getOutput());
        self::assertStringContainsString('removed vendor/acme/core -> ../../packages/core; run `composer install`', $process->getOutput());
        self::assertFalse(is_link($this->root . '/vendor/acme/core'));
        self::assertFileDoesNotExist($this->root . '/vendor/acme/core');
    }

    public function test_locked_copy_of_an_incompatible_package_is_left_alone(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        $this->checkout('core', 'acme/core', 'v2.0.0');
        $this->installLockedCopy('acme/core');

        $process = $this->runScript();

        self::assertStringContainsString('does not satisfy ^1.0', $process->getOutput());
        self::assertFalse(is_link($this->root . '/vendor/acme/core'));
        self::assertFileExists($this->root . '/vendor/acme/core/composer.json');
    }

    public function test_checkout_not_required_by_the_manifest_is_skipped_and_its_link_removed(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        $this->checkout('extra', 'acme/extra', 'v1.0.0');
        mkdir($this->root . '/vendor/acme', 0777, true);
        symlink('../../packages/extra', $this->root . '/vendor/acme/extra');

        $process = $this->runScript();

        self::assertStringContainsString('skipped vendor/acme/extra: acme/extra is not required by composer.json', $process->getOutput());
        self::assertStringContainsString('Composer does not list it as installed', $process->getOutput());
        self::assertFalse(is_link($this->root . '/vendor/acme/extra'));
    }

    public function test_checkout_without_a_tag_is_skipped_for_a_versioned_constraint(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        $this->checkout('core', 'acme/core', null);

        $process = $this->runScript();

        self::assertStringContainsString('the checkout has no release tag to check against ^1.0', $process->getOutput());
        self::assertFalse(is_link($this->root . '/vendor/acme/core'));
    }

    public function test_dev_constraints_and_path_repositories_are_always_linked(): void
    {
        $this->writeManifest([
            'repositories' => [['type' => 'path', 'url' => 'packages/by-path']],
            'require'      => ['acme/at-dev' => '@dev', 'acme/branch' => 'dev-main', 'acme/by-path' => '^9.0'],
        ]);
        $this->checkout('at-dev', 'acme/at-dev', null);
        $this->checkout('branch', 'acme/branch', 'v0.1.0');
        $this->checkout('by-path', 'acme/by-path', 'v1.0.0');

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        foreach (['at-dev', 'branch', 'by-path'] as $directory) {
            self::assertSame('../../packages/' . $directory, readlink($this->root . '/vendor/acme/' . $directory), $directory);
        }
    }

    public function test_overlay_manifest_from_the_composer_variable_is_the_one_read(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        $this->writeManifest([
            'repositories' => [['type' => 'path', 'url' => 'packages/extra']],
            'require'      => ['acme/core' => '^1.0', 'acme/extra' => '@dev'],
        ], 'composer.local.json');
        $this->checkout('extra', 'acme/extra', null);

        $plain = $this->runScript();

        self::assertStringContainsString('acme/extra is not required by composer.json', $plain->getOutput());
        self::assertFalse(is_link($this->root . '/vendor/acme/extra'));

        $overlaid = $this->runScript(environment: ['COMPOSER' => 'composer.local.json']);

        self::assertSame(0, $overlaid->getExitCode(), $overlaid->getErrorOutput());
        self::assertSame('../../packages/extra', readlink($this->root . '/vendor/acme/extra'));
    }

    public function test_link_of_a_package_installed_through_the_overlay_survives_a_run_without_it(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        $this->checkout('extra', 'acme/extra', null);
        mkdir($this->root . '/vendor/acme', 0777, true);
        symlink('../../packages/extra', $this->root . '/vendor/acme/extra');
        $this->writeInstalled(['acme/core', 'Acme/Extra']);

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('acme/extra is not required by composer.json', $process->getOutput());
        self::assertStringContainsString('kept vendor/acme/extra: Composer installed it through another manifest', $process->getOutput());
        self::assertSame('../../packages/extra', readlink($this->root . '/vendor/acme/extra'));
    }

    public function test_link_of_an_incompatible_package_is_removed_even_though_composer_lists_it(): void
    {
        $this->writeManifest(['require' => ['acme/core' => '^1.0']]);
        $this->checkout('core', 'acme/core', 'v2.0.0');
        mkdir($this->root . '/vendor/acme', 0777, true);
        symlink('../../packages/core', $this->root . '/vendor/acme/core');
        $this->writeInstalled(['acme/core']);

        $process = $this->runScript();

        self::assertStringContainsString('removed vendor/acme/core -> ../../packages/core; run `composer install`', $process->getOutput());
        self::assertFalse(is_link($this->root . '/vendor/acme/core'));
    }

    /**
     * @param  list<string>  $names
     */
    private function writeInstalled(array $names): void
    {
        mkdir($this->root . '/vendor/composer', 0777, true);
        file_put_contents(
            $this->root . '/vendor/composer/installed.json',
            json_encode(['packages' => array_map(static fn (string $name): array => ['name' => $name], $names)]),
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(array $manifest, string $file = 'composer.json'): void
    {
        file_put_contents($this->root . '/' . $file, json_encode(['name' => 'acme/root', ...$manifest]));
    }

    /**
     * A package checkout under packages/<directory>: its own git repository, tagged when $tag is given.
     */
    private function checkout(string $directory, string $name, ?string $tag): void
    {
        $path = $this->root . '/packages/' . $directory;
        mkdir($path, 0777, true);
        file_put_contents($path . '/composer.json', json_encode(['name' => $name]));

        $this->git($path, ['init', '--quiet']);
        $this->git($path, ['add', '-A']);
        $this->git($path, ['-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '--quiet', '-m', 'init']);

        if (null !== $tag) {
            $this->git($path, ['tag', $tag]);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $path, array $arguments): void
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->mustRun();
    }

    /**
     * A copy Composer installed from the lock: a real directory, not a link.
     */
    private function installLockedCopy(string $name): void
    {
        mkdir($this->root . '/vendor/' . $name, 0777, true);
        file_put_contents($this->root . '/vendor/' . $name . '/composer.json', json_encode(['name' => $name]));
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function runScript(array $arguments = [], array $environment = []): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/tools/dev-link-packages.php', ...$arguments],
            env: ['DEV_LINK_ROOT' => $this->root, 'COMPOSER' => false, ...$environment],
        );
        $process->run();

        return $process;
    }
}
