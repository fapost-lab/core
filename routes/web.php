<?php

declare(strict_types=1);

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PreSaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::post('/presale', [PreSaleController::class, 'store'])->name('presale.store');

Route::domain('{tenant}.fapost-core.test')
    ->middleware('tenant')
    ->group(function (): void {
        Route::get('/tenant-check', static fn (TenantContextInterface $tenantContext) => [
            'host'     => request()->getHost(),
            'resolved' => $tenantContext->isResolved(),
            'tenant'   => $tenantContext->isResolved()
                ? $tenantContext->get()->getSlug()
                : null,
            'schema' => DB::select('select current_schema()')[0]->current_schema ?? null,
        ]);
    });
