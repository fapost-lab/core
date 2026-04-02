<?php

declare(strict_types=1);

namespace App\Domains\Contact\Providers;

use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Services\ContactService;
use Illuminate\Support\ServiceProvider;

final class ContactServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContactServiceInterface::class, ContactService::class);
    }
}
