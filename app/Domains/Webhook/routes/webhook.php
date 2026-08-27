<?php

declare(strict_types=1);

use App\Domains\Webhook\Http\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/{channel}/{hash}', WebhookController::class)
    ->name('webhook.handle');
