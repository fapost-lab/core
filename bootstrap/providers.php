<?php

declare(strict_types=1);

use App\Domains\Channels\Providers\ChannelsServiceProvider;
use App\Domains\Contact\Providers\ContactServiceProvider;
use App\Domains\Conversation\Providers\ConversationServiceProvider;
use App\Domains\Flow\Providers\FlowServiceProvider;
use App\Domains\Health\Providers\HealthServiceProvider;
use App\Domains\Media\Providers\MediaServiceProvider;
use App\Domains\Messaging\Providers\MessageSenderServiceProvider;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Webhook\Providers\WebhookServiceProvider;
use App\Infrastructure\Onboarding\OnboardingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AssistantServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\StaffServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    AssistantServiceProvider::class,
    ChannelsServiceProvider::class,
    ContactServiceProvider::class,
    ConversationServiceProvider::class,
    FlowServiceProvider::class,
    HealthServiceProvider::class,
    MediaServiceProvider::class,
    MessageSenderServiceProvider::class,
    WebhookServiceProvider::class,
    StaffServiceProvider::class,
    HorizonServiceProvider::class,
    SolutionServiceProvider::class,
    OnboardingServiceProvider::class,
];
