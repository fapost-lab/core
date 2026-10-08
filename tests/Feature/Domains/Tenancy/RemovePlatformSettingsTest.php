<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

final class RemovePlatformSettingsTest extends FeatureTestCase
{
    public function test_settings_class_is_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Domains/Tenancy/Settings/PlatformSettings.php'));
        $this->assertNotContains(
            'App\\Domains\\Tenancy\\Settings\\PlatformSettings',
            config('settings.settings'),
        );
    }

    public function test_migrated_tenant_schema_has_no_platform_group(): void
    {
        $this->assertSame(0, DB::table('settings')->where('group', 'platform')->count());
    }

    public function test_migration_deletes_the_platform_group_and_keeps_others(): void
    {
        foreach (['version', 'maintenance_mode', 'default_locale', 'default_timezone'] as $name) {
            DB::table('settings')->insert([
                'group'      => 'platform',
                'name'       => $name,
                'locked'     => false,
                'payload'    => json_encode('x'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $tenantRows = DB::table('settings')->where('group', 'tenant')->count();

        $migration = require base_path('database/settings/2026_10_08_100000_remove_platform_group_from_tenant_settings.php');
        $migration->up();
        $migration->up();

        $this->assertSame(0, DB::table('settings')->where('group', 'platform')->count());
        $this->assertSame($tenantRows, DB::table('settings')->where('group', 'tenant')->count());
    }
}
