<?php

declare(strict_types=1);

use App\Domains\Staff\Http\Controllers\ActivationController;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Controllers\PreSaleController;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('tenancy.base_domain'))->group(function (): void {
    Route::get('/', function () {
        // The panel lives on the tenant host, so the link cannot be relative:
        // this page is served from the base domain, where /admin does not exist.
        //
        // In `host` mode there is no tenant of the installation to link to, so no link.
        $host     = TenantHost::forDefaultTenant();
        $hostMode = TenancyResolutionMode::Host === TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'));

        return view('welcome', [
            'adminUrl' => $hostMode ? null : (null === $host ? url('/admin') : request()->getScheme() . '://' . $host . '/admin'),
        ]);
    })->name('welcome');
    Route::post('/presale', [PreSaleController::class, 'store'])->name('presale.store');
});

// Activation needs a tenant: its tokens live in the tenant schema. In `host` mode only a tenant host
// reaches it and the base domain answers 404; in `single` mode the default tenant serves any host,
// so links issued before the move to the tenant host keep working.
Route::middleware('tenant')->group(function (): void {
    Route::get('/activate', [ActivationController::class, 'show'])->name('activate.show');
    Route::post('/activate', [ActivationController::class, 'store'])->name('activate.store');
});
