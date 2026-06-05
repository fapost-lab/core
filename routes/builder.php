<?php

declare(strict_types=1);

use App\Http\Controllers\Builder\AssistantOptionsController;
use App\Http\Controllers\Builder\BuilderFlowController;
use App\Http\Controllers\Builder\CallTestController;
use App\Http\Controllers\Builder\ContactTagsController;
use App\Http\Controllers\Builder\NodeTypesController;
use App\Http\Controllers\Builder\StaffOptionsController;
use App\Http\Middleware\SetBuilderRootView;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant', 'verified', SetBuilderRootView::class])->prefix('builder')->group(function (): void {
    Route::get('/flows/{flow}', [BuilderFlowController::class, 'show']);
    Route::put('/flows/{flow}/draft', [BuilderFlowController::class, 'saveDraft']);
    Route::post('/flows/{flow}/validate', [BuilderFlowController::class, 'validate']);
    Route::post('/flows/{flow}/publish', [BuilderFlowController::class, 'publish']);
    Route::get('/node-types', [NodeTypesController::class, 'index']);
    Route::get('/tags', [ContactTagsController::class, 'index']);
    Route::get('/staff', [StaffOptionsController::class, 'index']);
    Route::get('/assistants', [AssistantOptionsController::class, 'index']);
    Route::post('/call/test', CallTestController::class);
});
