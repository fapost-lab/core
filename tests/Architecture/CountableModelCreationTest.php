<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\AssistantService;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelService;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Staff\Services\CreatePendingUserService;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Providers\StaffServiceProvider;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * `new X` is all PHPat can see; static creates (`X::create()`, `X::query()->create()`) are
 * covered by `Tests\Unit\Architecture\CountableModelCreationTest`. Both read the countable models
 * from `Tests\Support\CountableModels`.
 */
final class CountableModelCreationTest
{
    public function test_assistant_is_constructed_only_by_assistant_service(): Rule
    {
        return $this->constructedOnlyBy(
            Assistant::class,
            [AssistantService::class],
            'The tenant assistant limit is checked in AssistantService::create(); constructing an Assistant elsewhere bypasses it.',
        );
    }

    public function test_flow_draft_is_constructed_only_by_create_flow_action(): Rule
    {
        return $this->constructedOnlyBy(
            FlowDraft::class,
            [CreateFlowAction::class],
            'The tenant flow limit is checked in CreateFlowAction; constructing a FlowDraft elsewhere bypasses it.',
        );
    }

    public function test_channel_is_constructed_only_by_channel_service(): Rule
    {
        return $this->constructedOnlyBy(
            Channel::class,
            [ChannelService::class],
            'The tenant channel limit is checked in ChannelService::create(); constructing a Channel elsewhere bypasses it.',
        );
    }

    public function test_user_is_constructed_only_by_staff_creators(): Rule
    {
        return $this->constructedOnlyBy(
            User::class,
            [CreatePendingUserService::class, AclBootstrapService::class],
            'The tenant staff limit is checked in CreatePendingUserService; AclBootstrapService creates the first admin of a tenant on purpose, without the check. Constructing a User elsewhere bypasses the limit.',
        );
    }

    public function test_first_admin_creation_is_reachable_only_from_tenant_provisioning(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App'),
                    Selector::NoneOf(
                        Selector::classname(AclBootstrapService::class),
                        Selector::classname(TenantProvisioningService::class),
                        Selector::classname(StaffServiceProvider::class),
                    ),
                ),
            )
            ->shouldNotDependOn()
            ->classes(Selector::classname(AclBootstrapService::class))
            ->because('AclBootstrapService::createFirstAdmin() creates a user without the staff limit check; only tenant provisioning (and the provider that binds it) may reach it.');
    }

    /**
     * @param  class-string        $model
     * @param  list<class-string>  $creators
     */
    private function constructedOnlyBy(string $model, array $creators, string $because): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App'),
                    Selector::NoneOf(
                        Selector::classname($model),
                        ...array_map(static fn (string $creator) => Selector::classname($creator), $creators),
                    ),
                ),
            )
            ->shouldNotConstruct()
            ->classes(Selector::classname($model))
            ->because($because);
    }
}
