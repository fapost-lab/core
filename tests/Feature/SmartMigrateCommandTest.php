<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class SmartMigrateCommandTest extends FeatureTestCase
{
    public function test_it_reports_pending_landlord_and_tenant_migrations_by_default(): void
    {
        $this->markLatestLandlordMigrationAsPending();
        $this->markLatestTenantMigrationAsPending();

        $exitCode = Artisan::call('migrate:smart');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Landlord pending: 1', $output);
        $this->assertStringContainsString('Tenant pending: 1', $output);
    }

    public function test_it_supports_landlord_only_flag(): void
    {
        $this->markLatestLandlordMigrationAsPending();
        $this->markLatestTenantMigrationAsPending();

        $exitCode = Artisan::call('migrate:smart', ['--landlord' => true]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Landlord pending: 1', $output);
        $this->assertStringNotContainsString('Tenant pending:', $output);
    }

    public function test_it_supports_all_flag(): void
    {
        $this->markLatestLandlordMigrationAsPending();
        $this->markLatestTenantMigrationAsPending();

        $exitCode = Artisan::call('migrate:smart', ['--all' => true]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Landlord pending: 1', $output);
        $this->assertStringContainsString('Tenant pending: 1', $output);
    }

    public function test_it_fails_when_default_local_tenant_is_missing(): void
    {
        config(['tenancy.default_tenant_slug' => 'missing']);

        $exitCode = Artisan::call('migrate:smart', ['--tenant' => true]);
        $output   = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Default local tenant [missing] was not found.', $output);
    }

    private function markLatestLandlordMigrationAsPending(): void
    {
        $migrationName = (string) DB::connection('landlord')
            ->table('migrations')
            ->max('migration');

        DB::connection('landlord')
            ->table('migrations')
            ->where('migration', $migrationName)
            ->delete();
    }

    private function markLatestTenantMigrationAsPending(): void
    {
        $migrationName = (string) DB::table('migrations')->max('migration');

        DB::table('migrations')
            ->where('migration', $migrationName)
            ->delete();
    }
}
