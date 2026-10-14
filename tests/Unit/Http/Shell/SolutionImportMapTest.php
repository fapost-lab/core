<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Shell;

use App\Domains\Staff\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Shell\SolutionImportMap;
use App\Http\Shell\SolutionUiMapInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The import map and the Solution bundle map of the console's root view (ADR solution-console-ui).
 */
final class SolutionImportMapTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withVite();
        $this->publicPath = sys_get_temp_dir() . '/solution-import-map-' . bin2hex(random_bytes(6));
        mkdir($this->publicPath . '/build', 0o777, true);
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_production_maps_every_specifier_to_the_built_shared_entries(): void
    {
        $this->writeManifest();

        $imports = $this->importMap()->imports();

        $this->assertNotNull($imports);
        $this->assertSame(asset('build/assets/vue-V1.js'), $imports['vue']);
        $this->assertSame(asset('build/assets/inertia-I1.js'), $imports['@inertiajs/vue3']);
        $this->assertSame(asset('build/assets/ui-U1.js'), $imports['@fapost/ui']);

        $subpaths = $this->uiSubpaths();
        $this->assertContains('components/button', $subpaths);
        $this->assertCount(3 + count($subpaths), $imports);

        foreach ($subpaths as $subpath) {
            $this->assertSame(asset('build/assets/ui-U1.js'), $imports['@fapost/ui/' . $subpath], $subpath);
        }
    }

    public function test_the_dev_server_maps_the_specifiers_to_the_entry_sources(): void
    {
        file_put_contents($this->publicPath . '/hot', 'http://localhost:5173');

        $imports = $this->importMap()->imports();

        $this->assertNotNull($imports);
        $this->assertSame('http://localhost:5173/resources/js/console/shared/vue.ts', $imports['vue']);
        $this->assertSame('http://localhost:5173/resources/js/console/shared/ui.ts', $imports['@fapost/ui/components/button']);
    }

    public function test_without_a_manifest_or_a_dev_server_only_the_bundle_map_is_rendered(): void
    {
        $html = $this->importMap()->render()->toHtml();

        $this->assertStringNotContainsString('importmap', $html);
        $this->assertSame('<script type="application/json" id="fapost-solutions">{}</script>', $html);
    }

    public function test_the_shared_entries_are_vite_entries_and_the_surface_is_what_ui_ts_re_exports(): void
    {
        $viteConfig = (string) file_get_contents(base_path('vite.config.ts'));
        $uiEntry    = (string) file_get_contents(base_path(SolutionImportMap::ENTRIES['@fapost/ui']));

        foreach (SolutionImportMap::ENTRIES as $entry) {
            $this->assertStringContainsString("'{$entry}'", $viteConfig);
        }

        $this->assertStringContainsString("preserveEntrySignatures: 'strict'", $viteConfig);

        foreach ($this->uiSubpaths() as $subpath) {
            $this->assertStringContainsString("export * from '@fapost/ui/{$subpath}'", $uiEntry);
        }
    }

    public function test_the_bundle_map_keeps_objects_as_objects_and_cannot_close_its_script(): void
    {
        $this->writeManifest();
        $this->app->instance(SolutionUiMapInterface::class, new class () implements SolutionUiMapInterface {
            public function bundles(): array
            {
                return [
                    'showcase' => [
                        'pages'      => ['Board/Index' => '/solutions/showcase/abc/assets/board-H.js'],
                        'components' => [],
                        'css'        => ['/solutions/showcase/abc/assets/style-H.css?</script><script>alert(1)</script>'],
                    ],
                ];
            }

            public function versionSuffix(): string
            {
                return '';
            }
        });

        // A visitor gets no map; a signed-in user does.
        $this->assertStringContainsString('id="fapost-solutions">{}</script>', $this->importMap()->render()->toHtml());
        $this->actingAs(User::factory()->make());

        $html = $this->importMap()->render()->toHtml();

        $this->assertSame(1, mb_substr_count($html, '<script type="importmap">'));
        $this->assertLessThan(mb_strpos($html, 'id="fapost-solutions"'), mb_strpos($html, '<script type="importmap">'));
        $this->assertSame(2, mb_substr_count($html, '</script>'));

        preg_match('#<script type="application/json" id="fapost-solutions">(.*)</script>#s', $html, $match);
        $this->assertSame(
            '{"showcase":{"pages":{"Board/Index":"/solutions/showcase/abc/assets/board-H.js"},"components":{},"css":["/solutions/showcase/abc/assets/style-H.css?\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"]}}',
            $match[1],
        );
    }

    public function test_the_inertia_version_carries_the_suffix_of_the_tenants_solutions(): void
    {
        $this->writeManifest();
        $middleware = new HandleInertiaRequests();
        $base       = $middleware->version(Request::create('/'));

        $this->assertSame('', app(SolutionUiMapInterface::class)->versionSuffix(), 'no Solution, no suffix');
        $this->assertNotNull($base);

        $this->app->instance(SolutionUiMapInterface::class, new class () implements SolutionUiMapInterface {
            public function bundles(): array
            {
                return [];
            }

            public function versionSuffix(): string
            {
                return ':showcase@abc';
            }
        });

        $this->assertSame($base . ':showcase@abc', $middleware->version(Request::create('/')));
    }

    private function importMap(): SolutionImportMap
    {
        app(Vite::class)->useHotFile($this->publicPath . '/hot');

        return app(SolutionImportMap::class);
    }

    private function writeManifest(): void
    {
        $manifest = [];

        foreach (['vue' => 'V1', 'inertia' => 'I1', 'ui' => 'U1'] as $name => $hash) {
            $source            = "resources/js/console/shared/{$name}.ts";
            $manifest[$source] = ['file' => "assets/{$name}-{$hash}.js", 'name' => $name, 'src' => $source, 'isEntry' => true];
        }

        file_put_contents($this->publicPath . '/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    private function uiSubpaths(): array
    {
        /** @var array{subpaths: list<string>} $surface */
        $surface = json_decode((string) file_get_contents(resource_path(SolutionImportMap::UI_SURFACE)), true, 3, JSON_THROW_ON_ERROR);

        return $surface['subpaths'];
    }
}
