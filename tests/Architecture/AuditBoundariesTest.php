<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The audit log records what people do in the console and the builder; it never sits on the hot path.
 */
final class AuditBoundariesTest
{
    public function test_audit_domain_depends_on_no_other_domain_but_the_tenancy_contracts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Domains\\Audit'))
            ->shouldNotDependOn()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('#^App\\\\Domains\\\\#', true),
                    Selector::NoneOf(
                        Selector::inNamespace('App\\Domains\\Audit'),
                        Selector::inNamespace('App\\Domains\\Tenancy\\Contracts'),
                    ),
                ),
            )
            ->because('Every domain calls the audit trail, so the Audit domain depends on none of them; what knows Staff (the actor, the permission) lives in App\\Infrastructure\\Audit.');
    }

    public function test_the_hot_path_does_not_record_audit_entries(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::classname('App\\Domains\\Flow\\Services\\FlowEngine'),
                Selector::inNamespace('App\\Domains\\Flow\\Handlers'),
                Selector::inNamespace('App\\Domains\\Flow\\Orchestration'),
                Selector::inNamespace('App\\Domains\\Flow\\Registry'),
                Selector::inNamespace('App\\Domains\\Flow\\Routing'),
                Selector::inNamespace('App\\Domains\\Flow\\State'),
                Selector::inNamespace('App\\Domains\\Flow\\Subflow'),
                Selector::inNamespace('App\\Domains\\Messaging'),
                Selector::inNamespace('App\\Domains\\Webhook'),
                // The flow webhook ingress shares App\Domains\Flow\Webhook with the builder's audited endpoint service.
                Selector::classname('App\\Domains\\Flow\\Webhook\\FlowWebhookReceiver'),
                Selector::classname('App\\Domains\\Flow\\Webhook\\WebhookSyncWait'),
                Selector::classname('App\\Http\\Controllers\\Flow\\FlowWebhookController'),
                // The API trigger's ingress: the run and its `flow.api.token.id` are the log of a call (adr-20261119-tenant-api-tokens §12).
                Selector::inNamespace('App\\Domains\\Flow\\Api'),
                Selector::classname('App\\Domains\\Flow\\Services\\LiveTriggerLookup'),
                Selector::classname('App\\Http\\Controllers\\Flow\\FlowApiTriggerController'),
                Selector::inNamespace('App\\Domains\\Broadcasting\\Jobs'),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\\Domains\\Audit'),
                Selector::inNamespace('App\\Infrastructure\\Audit'),
            )
            ->because('The audit log records people\'s actions synchronously in their transaction; on the flow runtime, message delivery, webhook or API trigger ingress or broadcast fan-out it would add an insert per message and record system noise as actions.');
    }
}
