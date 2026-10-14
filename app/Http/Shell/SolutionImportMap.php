<?php

declare(strict_types=1);

namespace App\Http\Shell;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use JsonException;
use stdClass;

/**
 * The console root view's import map and map of Solution bundles, rendered by `@solutionImports` before `@vite`
 * (ADR solution-console-ui).
 *
 * The import map points the bare specifiers a prebuilt Solution bundle keeps external — `vue`, `@inertiajs/vue3`,
 * `@fapost/ui` and every public `@fapost/ui/<subpath>` — at Core's three shared Vite entries, whose code lives in the
 * chunks Core's own console imports: one Vue, one Inertia router, one kit. In production the URLs come from the Vite
 * manifest, on the dev server they are the entries' sources there, which Vite rewrites to the same optimized
 * dependencies the console uses. A browser applies an import map only before the first module script, hence before
 * `@vite`.
 *
 * Beside it, `#fapost-solutions` lists the bundles of the tenant's active Solutions for the console's page resolver;
 * empty for a visitor who is not signed in.
 *
 * @phpstan-import-type SolutionBundle from SolutionUiMapInterface
 */
final readonly class SolutionImportMap
{
    /**
     * Shared Vite entries by the specifier they stand for.
     *
     * @var array<string, string>
     */
    public const array ENTRIES = [
        'vue'             => 'resources/js/console/shared/vue.ts',
        '@inertiajs/vue3' => 'resources/js/console/shared/inertia.ts',
        '@fapost/ui'      => 'resources/js/console/shared/ui.ts',
    ];

    /**
     * The public subpaths of `@fapost/ui`, the same list `shared/ui.ts` re-exports.
     */
    public const string UI_SURFACE = 'js/console/shared/ui-surface.json';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

    public function __construct(
        private Vite $vite,
        private SolutionUiMapInterface $solutions,
        private AuthFactory $auth,
    ) {
    }

    /**
     * Both scripts, ready for the root view.
     *
     * @throws JsonException
     */
    public function render(): HtmlString
    {
        $imports = $this->imports();
        $html    = [];

        if (null !== $imports) {
            $html[] = '<script type="importmap">' . json_encode(['imports' => $imports], self::JSON_FLAGS) . '</script>';
        }

        $html[] = '<script type="application/json" id="fapost-solutions">' . json_encode($this->bundles(), self::JSON_FLAGS) . '</script>';

        return new HtmlString(implode("\n", $html));
    }

    /**
     * Specifier to URL; `null` when there is neither a dev server nor a built manifest to take URLs from, as in tests
     * that render pages without assets.
     *
     * @return array<string, string>|null
     *
     * @throws JsonException
     */
    public function imports(): ?array
    {
        if (! $this->vite->isRunningHot() && null === $this->vite->manifestHash()) {
            return null;
        }

        $urls = array_map(fn (string $entry): string => $this->vite->asset($entry), self::ENTRIES);

        foreach ($this->uiSubpaths() as $subpath) {
            $urls['@fapost/ui/' . $subpath] = $urls['@fapost/ui'];
        }

        return $urls;
    }

    /**
     * The bundles in the shape the page resolver reads: objects stay objects in JSON even when empty.
     */
    private function bundles(): stdClass
    {
        $bundles = new stdClass();

        // The sign-in page is the console's too: a visitor learns nothing about the tenant's Solutions.
        if (! $this->auth->guard()->check()) {
            return $bundles;
        }

        foreach ($this->solutions->bundles() as $id => $bundle) {
            $bundles->{$id} = [
                'pages'      => (object) $bundle['pages'],
                'components' => (object) $bundle['components'],
                'css'        => array_values($bundle['css']),
            ];
        }

        return $bundles;
    }

    /**
     * @return list<string>
     *
     * @throws JsonException
     */
    private function uiSubpaths(): array
    {
        /** @var array{subpaths: list<string>} $surface */
        $surface = json_decode((string) file_get_contents(resource_path(self::UI_SURFACE)), true, 3, JSON_THROW_ON_ERROR);

        return $surface['subpaths'];
    }
}
