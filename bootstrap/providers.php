<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\StaffServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    StaffServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
];
