<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Filesystem\Filesystem;
use Tests\Feature\FeatureTestCase;

/**
 * The console's root view renders the import map before the first module script: a browser ignores an import map
 * that arrives after module loading started (ADR solution-console-ui).
 */
final class SolutionImportsRootViewTest extends FeatureTestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withVite();
        $this->publicPath = sys_get_temp_dir() . '/solution-imports-view-' . bin2hex(random_bytes(6));
        mkdir($this->publicPath . '/build', 0o777, true);
        $this->app->usePublicPath($this->publicPath);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_the_import_map_comes_once_and_before_every_module_script(): void
    {
        $this->writeManifest();

        $html = (string) $this->get($this->panelUrl('/admin/login'))->assertOk()->assertViewIs('console')->getContent();

        $importMap = mb_strpos($html, '<script type="importmap">');

        $this->assertNotFalse($importMap);
        $this->assertSame(1, mb_substr_count($html, 'type="importmap"'));
        $this->assertStringContainsString('"vue":"' . asset('build/assets/vue-V1.js') . '"', $html);
        $this->assertStringContainsString('<script type="application/json" id="fapost-solutions">{}</script>', $html);

        $firstModule  = mb_strpos($html, 'type="module"');
        $firstPreload = mb_strpos($html, 'rel="modulepreload"');

        $this->assertNotFalse($firstModule, 'the page has no module script, so the order proves nothing');
        $this->assertLessThan($firstModule, $importMap);

        if (false !== $firstPreload) {
            $this->assertLessThan($firstPreload, $importMap);
        }
    }

    private function writeManifest(): void
    {
        $manifest = [
            'resources/css/ui.css'        => ['file' => 'assets/ui-C1.css', 'src' => 'resources/css/ui.css', 'isEntry' => true],
            'resources/js/console/app.ts' => [
                'file'    => 'assets/app-A1.js',
                'src'     => 'resources/js/console/app.ts',
                'isEntry' => true,
                'imports' => ['_runtime-R1.js'],
            ],
            '_runtime-R1.js' => ['file' => 'assets/runtime-R1.js', 'name' => 'runtime'],
        ];

        foreach (['vue' => 'V1', 'inertia' => 'I1', 'ui' => 'U1'] as $name => $hash) {
            $source            = "resources/js/console/shared/{$name}.ts";
            $manifest[$source] = ['file' => "assets/{$name}-{$hash}.js", 'name' => $name, 'src' => $source, 'isEntry' => true, 'imports' => ['_runtime-R1.js']];
        }

        file_put_contents($this->publicPath . '/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
