<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use Tests\TestCase;

/**
 * The versions in `config/solutions.php` are what the activation screen compares a Solution's bundle with. PHP cannot read
 * node_modules in a deployed image, so the lock file is where a test checks them.
 */
final class ConsoleRuntimeConfigTest extends TestCase
{
    public function test_the_configured_runtime_is_what_the_lock_file_installs(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, 512, JSON_THROW_ON_ERROR)['packages'];

        $this->assertSame($lock['node_modules/@fapost/ui']['version'], config('solutions.console_runtime.kit'));
        $this->assertSame($lock['node_modules/vue']['version'], config('solutions.console_runtime.vue'));
        $this->assertSame($lock['node_modules/@inertiajs/vue3']['version'], config('solutions.console_runtime.inertia'));
    }
}
