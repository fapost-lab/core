<?php

declare(strict_types=1);

use App\Domains\Channels\Providers\ChannelsServiceProvider;
use App\Domains\Contact\Providers\ContactServiceProvider;
use App\Domains\Flow\Providers\FlowServiceProvider;
use App\Domains\Messaging\Providers\MessageSenderServiceProvider;
use App\Domains\Webhook\Providers\WebhookServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AssistantServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AssistantPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\HrSolutionServiceProvider;
use App\Providers\StaffServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    AssistantServiceProvider::class,
    ChannelsServiceProvider::class,
    ContactServiceProvider::class,
    FlowServiceProvider::class,
    MessageSenderServiceProvider::class,
    WebhookServiceProvider::class,
    StaffServiceProvider::class,
    AdminPanelProvider::class,
    AssistantPanelProvider::class,
    HorizonServiceProvider::class,
    HrSolutionServiceProvider::class,
];
