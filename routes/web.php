<?php

declare(strict_types=1);

use App\Domains\Staff\Http\Controllers\ActivationController;
use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Controllers\PreSaleController;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('tenancy.base_domain'))->group(function (): void {
    Route::get('/', function () {
        // The panel lives on the tenant host, so the link cannot be relative:
        // this page is served from the base domain, where /admin does not exist.
        $host = TenantHost::forDefaultTenant();

        return view('welcome', [
            'adminUrl' => null === $host ? url('/admin') : request()->getScheme() . '://' . $host . '/admin',
        ]);
    })->name('welcome');
    Route::post('/presale', [PreSaleController::class, 'store'])->name('presale.store');

    Route::get('/activate', [ActivationController::class, 'show'])->name('activate.show');
    Route::post('/activate', [ActivationController::class, 'store'])->name('activate.store');
});
