<?php

declare(strict_types=1);


use Illuminate\Support\Facades\Route;

Route::domain('{tenant}.' . config('tenancy.base_domain'))->group(function (): void {
    Route::view('/', 'welcome');
});
