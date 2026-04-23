<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class ViteEntrypointsTest extends TestCase
{
    public function test_blade_vite_assets_are_declared_as_entrypoints(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        $viteConfig = (string) file_get_contents($projectRoot . '/vite.config.js');
        $this->assertNotSame('', $viteConfig);

        preg_match_all("/'resources\\/[^\']+'/", $viteConfig, $matches);

        $entrypoints = array_map(
            static fn (string $entrypoint): string => mb_trim($entrypoint, "'"),
            $matches[0],
        );

        $builderView = (string) file_get_contents($projectRoot . '/resources/views/builder.blade.php');
        $this->assertNotSame('', $builderView);

        preg_match_all("/'resources\\/[^\']+'/", $builderView, $matches);

        $referencedAssets = array_map(
            static fn (string $asset): string => mb_trim($asset, "'"),
            $matches[0],
        );

        foreach ($referencedAssets as $asset) {
            $this->assertContains(
                $asset,
                $entrypoints,
                sprintf('The [%s] Blade asset is missing from Vite entrypoints.', $asset),
            );
        }
    }
}
