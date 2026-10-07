<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\AssistantService;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelService;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Staff\Services\CreatePendingUserService;

/**
 * Models whose count a tenant limit caps. Every creation path of these must go through the
 * service that checks the limit; {@see \Tests\Unit\Architecture\CountableModelCreationTest}
 * scans `app/` for the ones that do not.
 *
 * Add a model here when a limit starts counting it.
 */
final class CountableModels
{
    /**
     * Countable model => the classes allowed to create it and the name of the relation that points at it.
     *
     * A model has more than one creator only when one of them is a deliberate exception to the
     * limit: the first administrator of a tenant (`AclBootstrapService`) is created before any
     * limit applies and still counts toward it.
     *
     * @return array<class-string, array{creators: list<class-string>, relation: string}>
     */
    public static function models(): array
    {
        return [
            Assistant::class => ['creators' => [AssistantService::class], 'relation' => 'assistants'],
            FlowDraft::class => ['creators' => [CreateFlowAction::class], 'relation' => 'drafts'],
            Channel::class   => ['creators' => [ChannelService::class], 'relation' => 'channels'],
            User::class      => ['creators' => [CreatePendingUserService::class, AclBootstrapService::class], 'relation' => 'users'],
        ];
    }
}
