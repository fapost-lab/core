<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * A throwaway `vendor/` directory with an installed.json and the packages it lists, for tests of Solution discovery.
 */
final class FakeVendor
{
    public readonly string $path;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/fapost-vendor-' . bin2hex(random_bytes(6)) . '/vendor';
        mkdir($this->path . '/composer', 0777, true);
    }

    /**
     * composer.json of the fixture Solution (tests/Fixtures/Solution/packages/feedback), with the changes given.
     *
     * @param  array<string, mixed>  $fapost  replaces keys of extra.fapost; a null value removes the key
     * @param  array<string, mixed>  $root  replaces top-level keys; a null value removes the key
     * @return array<string, mixed>
     */
    public static function solutionComposer(array $fapost = [], array $root = []): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/Solution/packages/feedback/composer.json'), true);

        foreach ($fapost as $key => $value) {
            if (null === $value) {
                unset($composer['extra']['fapost'][$key]);
            } else {
                $composer['extra']['fapost'][$key] = $value;
            }
        }

        foreach ($root as $key => $value) {
            if (null === $value) {
                unset($composer[$key]);
            } else {
                $composer[$key] = $value;
            }
        }

        return $composer;
    }

    /**
     * Lists a package in installed.json and writes its composer.json under vendor/<name>.
     *
     * @param  array<string, mixed>  $composer
     */
    public function package(array $composer, string $version = '1.0.0', bool $withComposerJson = true): self
    {
        $name = (string) $composer['name'];

        if ($withComposerJson) {
            $this->writePackageFile($name, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->entries[] = ['version' => $version, 'install-path' => '../' . $name] + $composer;

        return $this;
    }

    /**
     * Lists a package whose install path is a symlink to a directory elsewhere, as `composer dev:link` leaves it.
     *
     * @param  array<string, mixed>  $listed  what installed.json says about the package
     */
    public function linkedPackage(array $listed, string $target, string $version = 'dev-main'): self
    {
        $name = (string) $listed['name'];
        mkdir(dirname($this->path . '/' . $name), 0777, true);
        symlink($target, $this->path . '/' . $name);

        $this->entries[] = ['version' => $version, 'install-path' => '../' . $name] + $listed;

        return $this;
    }

    public function writePackageFile(string $package, string $contents, string $file = 'composer.json'): void
    {
        $directory = $this->path . '/' . $package;

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($directory . '/' . $file, $contents);
    }

    /**
     * Writes installed.json in the Composer 2 shape, or the Composer 1 shape (a bare list).
     */
    public function write(bool $composerOne = false): self
    {
        $document = $composerOne ? $this->entries : ['packages' => $this->entries];

        file_put_contents($this->path . '/composer/installed.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this;
    }

    public function installedJson(): string
    {
        return $this->path . '/composer/installed.json';
    }

    public function cleanup(): void
    {
        (new Filesystem())->deleteDirectory(dirname($this->path));
    }
}
