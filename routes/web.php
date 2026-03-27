<?php

declare(strict_types=1);

use App\Domains\Staff\Http\Controllers\ActivationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PreSaleController;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('tenancy.base_domain'))->group(function (): void {
    Route::get('/', [LandingController::class, 'index'])->name('landing');
    Route::post('/presale', [PreSaleController::class, 'store'])->name('presale.store');

    Route::get('/activate', [ActivationController::class, 'show'])->name('activate.show');
    Route::post('/activate', [ActivationController::class, 'store'])->name('activate.store');
});
