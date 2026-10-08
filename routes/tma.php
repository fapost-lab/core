<?php

declare(strict_types=1);

use App\Http\Controllers\Tma\TmaFormController;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::prefix('tma/api')
    ->middleware(['tenant', 'tma.auth', RefuseWritesWhenTenantStopped::class])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->group(function (): void {
        Route::get('/forms/{formId}', [TmaFormController::class, 'show']);
        Route::post('/forms/{formId}/submit', [TmaFormController::class, 'submit']);
    });

Route::get('/tma/{any?}', fn () => view('tma'))->where('any', '.*');
