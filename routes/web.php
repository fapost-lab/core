<?php

declare(strict_types=1);

use App\Http\Controllers\LandingController;
use App\Http\Controllers\PreSaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::post('/presale', [PreSaleController::class, 'store'])->name('presale.store');
