<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionUiBundle;
use App\Domains\Solution\DTO\SolutionUiSource;
use Fapost\Foundation\Console\Bundle\UiBundleParser;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads a Solution's `fapost-ui.json` from its package: the Foundation rules ({@see UiBundleParser}), then what needs
 * the files — the description is there and readable, every page, component and stylesheet it lists exists inside its
 * directory, and the directory is safe to put in the web root (see {@see self::publishable()}). Read-only; publication
 * is {@see SolutionUiPublisher}'s.
 */
final readonly class SolutionUiBundleReader
{
    /** Length of the hash that names a published bundle directory. */
    public const int HASH_LENGTH = 12;

    /**
     * Extensions of the files publication copies, besides `fapost-ui.json`: scripts, styles, source maps, fonts and
     * raster images. No SVG (it can carry script), no HTML, nothing a server would execute.
     *
     * @var list<string>
     */
    public const array STATIC_EXTENSIONS = ['js', 'mjs', 'css', 'map', 'woff', 'woff2', 'ttf', 'otf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico'];

    /** A file name with a PHP extension in any position (`x.php`, `x.php.js`, `x.PHTML.css`); one refuses the bundle. */
    private const string EXECUTABLE = '/\.(php\d*|phtml|phar|pht|phps)(\.|$)/i';

    public function __construct(private UiBundleParser $parser)
    {
    }

    /**
     * @return SolutionUiSource|null null when the Solution declares no console UI
     */
    public function read(InstalledSolution $solution): ?SolutionUiSource
    {
        if (null === $solution->ui) {
            return null;
        }

        $id   = $solution->id;
        $path = $solution->uiPath();

        if (null === $path) {
            return SolutionUiSource::invalid($id, null, [sprintf('%s: extra.fapost.ui: the package ships no composer.json on disk, so "%s" cannot be found', $id, $solution->ui)]);
        }

        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if (false === $contents) {
            return SolutionUiSource::invalid($id, $path, [sprintf('%s: extra.fapost.ui: "%s" does not exist; build the console UI before installing', $id, $solution->ui)]);
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return SolutionUiSource::invalid($id, $path, [sprintf('%s: fapost-ui.json: is not valid JSON (%s)', $id, $exception->getMessage())]);
        }

        if (! is_array($decoded)) {
            return SolutionUiSource::invalid($id, $path, [sprintf('%s: fapost-ui.json: (root): must be an object', $id)]);
        }

        $result = $this->parser->parse($decoded, $id);

        if (null === $result->bundle) {
            return SolutionUiSource::invalid($id, $path, $result->lines());
        }

        $directory = dirname($path);
        $bundle    = $result->bundle;
        $problems  = [];

        $listed = [
            ...array_map(static fn (string $page): string => 'pages.' . $page, array_flip($bundle->pages)),
            ...array_map(static fn (string $component): string => 'components.' . $component, array_flip($bundle->components)),
        ];

        foreach ($bundle->css as $index => $file) {
            $listed[$file] ??= sprintf('css[%d]', $index);
        }

        foreach ($listed as $file => $field) {
            if (! $this->isInside($directory, $directory . '/' . $file)) {
                $problems[] = sprintf('%s: fapost-ui.json: %s: "%s" does not exist next to fapost-ui.json', $id, $field, $file);
            }
        }

        $warnings = [];
        $files    = $this->publishable($id, $solution, $directory, $path, $problems, $warnings);

        // A listed page, component or stylesheet that publication would leave behind could never load.
        foreach ($listed as $file => $field) {
            if ([] === $problems && ! in_array($file, $files, true)) {
                $problems[] = sprintf('%s: fapost-ui.json: %s: "%s" cannot be published (allowed: %s)', $id, $field, $file, implode(', ', self::STATIC_EXTENSIONS));
            }
        }

        if ([] !== $problems) {
            return SolutionUiSource::invalid($id, $path, $problems);
        }

        return new SolutionUiSource(
            $id,
            $directory,
            $path,
            new SolutionUiBundle($id, mb_substr(hash('sha256', $contents), 0, self::HASH_LENGTH), $bundle->pages, $bundle->components, $bundle->css),
            [],
            $files,
            $warnings,
        );
    }

    /**
     * The files of the bundle directory that publication copies into the web root: regular files with a static asset
     * extension, and `fapost-ui.json` itself, outside hidden paths. Everything else stays behind. A directory that would
     * expose the package is refused instead: the package root, one holding a file with PHP anywhere in its name (a web
     * server executes it by URL), or a link that leads outside the directory.
     *
     * Any other visible file is left behind with a warning: a page that imports it would fail in the browser.
     *
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     *
     * @return list<string> paths relative to the bundle directory, sorted
     */
    private function publishable(string $id, InstalledSolution $solution, string $directory, string $manifestFile, array &$problems, array &$warnings): array
    {
        $root    = realpath($directory);
        $package = null === $solution->composerPath ? false : realpath(dirname($solution->composerPath));

        if (false === $root) {
            $problems[] = sprintf('%s: extra.fapost.ui: the directory of "%s" cannot be read', $id, $solution->ui);

            return [];
        }

        if ($root === $package) {
            $problems[] = sprintf('%s: extra.fapost.ui: "%s" sits at the package root; the built console UI must have a directory of its own, such as dist/', $id, $solution->ui);

            return [];
        }

        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relative  = mb_substr($item->getPathname(), mb_strlen($directory) + 1);
            $extension = mb_strtolower($item->getExtension());

            // Hidden paths (`.git/`, `.env`, `.secret.js`) are the author's tooling, never the bundle: left behind.
            if (1 === preg_match('#(^|/)\.#', $relative)) {
                continue;
            }

            // A server may run `x.php.js` as PHP (Apache's multiple extensions), so PHP anywhere in a name refuses it.
            if (1 === preg_match(self::EXECUTABLE, $item->getFilename())) {
                $problems[] = sprintf('%s: fapost-ui.json: "%s" is PHP; the bundle directory is published to the web root and must hold none', $id, $relative);

                continue;
            }

            $target = $item->isLink() ? realpath($item->getPathname()) : null;

            if (false === $target || (null !== $target && ! str_starts_with($target, $root . DIRECTORY_SEPARATOR))) {
                $problems[] = sprintf('%s: fapost-ui.json: "%s" is a link that leads outside the bundle directory', $id, $relative);

                continue;
            }

            $isManifest = $item->getPathname() === $manifestFile;

            if (! $item->isFile()) {
                continue;
            }

            if ($isManifest || in_array($extension, self::STATIC_EXTENSIONS, true)) {
                $files[] = $relative;
            } else {
                $warnings[] = sprintf('%s: fapost-ui.json: %s is not published (allowed: %s)', $id, $relative, implode(', ', self::STATIC_EXTENSIONS));
            }
        }

        sort($files);
        sort($warnings);

        return $files;
    }

    /**
     * Whether the file exists and, links resolved, stays inside the bundle directory.
     */
    private function isInside(string $directory, string $file): bool
    {
        $root = realpath($directory);
        $real = is_file($file) ? realpath($file) : false;

        return false !== $root && false !== $real && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }
}
