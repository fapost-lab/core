<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class DevLinkNpmScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-dev-link-npm-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/node_modules', 0777, true);
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

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function caret_zero_boundaries(): iterable
    {
        yield '^0.1.1 accepts 0.1.1' => ['^0.1.1', '0.1.1', true];
        yield '^0.1.1 accepts 0.1.9' => ['^0.1.1', '0.1.9', true];
        yield '^0.1.1 refuses 0.1.0' => ['^0.1.1', '0.1.0', false];
        yield '^0.1.1 refuses 0.2.0' => ['^0.1.1', '0.2.0', false];
        yield '^0.1.1 refuses 0.2.0-beta.1' => ['^0.1.1', '0.2.0-beta.1', false];
        yield '^0.0.3 accepts 0.0.3' => ['^0.0.3', '0.0.3', true];
        yield '^0.0.3 refuses 0.0.4' => ['^0.0.3', '0.0.4', false];
        yield '^1.2.3 accepts 1.9.0' => ['^1.2.3', '1.9.0', true];
        yield '^1.2.3 refuses 2.0.0' => ['^1.2.3', '2.0.0', false];
        yield '~1.2.3 refuses 1.3.0' => ['~1.2.3', '1.3.0', false];
        yield '>=1.0.0 accepts 3.0.0' => ['>=1.0.0', '3.0.0', true];
        yield 'exact version' => ['1.2.3', '1.2.3', true];
        yield 'exact version refuses a neighbour' => ['1.2.3', '1.2.4', false];
        yield 'star accepts anything' => ['*', '9.9.9', true];
        yield 'or ranges' => ['^1.0.0 || ^3.0.0', '3.1.0', true];
        yield 'a pre-release is below its release' => ['>=1.0.0', '1.0.0-rc.1', false];
    }

    public function test_checkout_whose_version_satisfies_the_constraint_is_linked(): void
    {
        $this->writeManifest(['dependencies' => ['@acme/ui' => '^0.1.1']]);
        $this->checkout('ui', '@acme/ui', '0.1.4');
        $this->installRegistryCopy('@acme/ui');

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('../../packages/ui', readlink($this->root . '/node_modules/@acme/ui'));
        self::assertStringContainsString('[dev:link] node_modules/@acme/ui -> ../../packages/ui', $process->getOutput());
    }

    public function test_checkout_of_an_incompatible_version_is_skipped_and_its_link_removed(): void
    {
        $this->writeManifest(['dependencies' => ['@acme/ui' => '^1.2.0']]);
        $this->checkout('ui', '@acme/ui', '2.0.0');
        mkdir($this->root . '/node_modules/@acme', 0777, true);
        symlink('../../packages/ui', $this->root . '/node_modules/@acme/ui');

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('skipped node_modules/@acme/ui: the checkout is at 2.0.0, which does not satisfy ^1.2.0', $process->getOutput());
        self::assertStringContainsString('removed node_modules/@acme/ui -> ../../packages/ui; run `npm ci`', $process->getOutput());
        self::assertFalse(is_link($this->root . '/node_modules/@acme/ui'));
    }

    public function test_registry_copy_of_a_refused_package_is_left_alone(): void
    {
        $this->writeManifest(['devDependencies' => ['@acme/ui' => '^1.0.0']]);
        $this->checkout('ui', '@acme/ui', '2.0.0');
        $this->installRegistryCopy('@acme/ui');

        $process = $this->runScript();

        self::assertStringContainsString('does not satisfy ^1.0.0', $process->getOutput());
        self::assertFalse(is_link($this->root . '/node_modules/@acme/ui'));
        self::assertFileExists($this->root . '/node_modules/@acme/ui/package.json');
    }

    #[DataProvider('caret_zero_boundaries')]
    public function test_range_boundaries(string $constraint, string $version, bool $linked): void
    {
        $this->writeManifest(['dependencies' => ['@acme/ui' => $constraint]]);
        $this->checkout('ui', '@acme/ui', $version);

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($linked, is_link($this->root . '/node_modules/@acme/ui'), $process->getOutput());
    }

    public function test_link_file_and_workspace_specs_are_always_linked(): void
    {
        $this->writeManifest(['dependencies' => ['@acme/a' => 'link:../packages/a', '@acme/b' => 'file:../packages/b']]);
        $this->checkout('a', '@acme/a', '9.0.0');
        $this->checkout('b', '@acme/b', null);

        $process = $this->runScript();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertTrue(is_link($this->root . '/node_modules/@acme/a'));
        self::assertTrue(is_link($this->root . '/node_modules/@acme/b'));
    }

    public function test_unparseable_constraint_skips_the_package(): void
    {
        $this->writeManifest(['dependencies' => ['@acme/ui' => 'latest']]);
        $this->checkout('ui', '@acme/ui', '1.0.0');

        $process = $this->runScript();

        self::assertStringContainsString('not one this script understands', $process->getOutput());
        self::assertFalse(is_link($this->root . '/node_modules/@acme/ui'));
    }

    public function test_package_not_declared_by_core_is_ignored(): void
    {
        $this->writeManifest(['dependencies' => ['@acme/ui' => '^1.0.0']]);
        $this->checkout('other', '@acme/other', '1.0.0');

        $this->runScript();

        self::assertFalse(is_link($this->root . '/node_modules/@acme/other'));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->root . '/package.json', json_encode(['name' => 'root', ...$manifest]));
    }

    private function checkout(string $directory, string $name, ?string $version): void
    {
        $path = $this->root . '/packages/' . $directory;
        mkdir($path . '/node_modules/vue', 0777, true);
        file_put_contents($path . '/package.json', json_encode(array_filter(['name' => $name, 'version' => $version])));
    }

    /**
     * A copy npm installed from the registry: a real directory, not a link.
     */
    private function installRegistryCopy(string $name): void
    {
        mkdir($this->root . '/node_modules/' . $name, 0777, true);
        file_put_contents($this->root . '/node_modules/' . $name . '/package.json', json_encode(['name' => $name]));
    }

    private function runScript(): Process
    {
        $node = (new ExecutableFinder())->find('node');

        if (null === $node) {
            self::markTestSkipped('node is not on PATH');
        }

        $process = new Process(
            [$node, dirname(__DIR__, 3) . '/tools/dev-link-npm.mjs'],
            env: ['DEV_LINK_ROOT' => $this->root],
        );
        $process->run();

        return $process;
    }
}
