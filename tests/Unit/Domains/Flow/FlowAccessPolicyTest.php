<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Services\FlowAccessPolicy;
use Tests\TestCase;

final class FlowAccessPolicyTest extends TestCase
{
    public function test_public_flow_is_startable_by_anyone(): void
    {
        $policy  = new FlowAccessPolicy();
        $contact = Contact::make(['is_authenticated' => false]);

        $this->assertTrue($policy->canStart(FlowDefinition::make(['is_public' => true]), $contact));
    }

    public function test_private_flow_blocks_unauthenticated_contact(): void
    {
        $policy  = new FlowAccessPolicy();
        $contact = Contact::make(['is_authenticated' => false]);

        $this->assertFalse($policy->canStart(FlowDefinition::make(['is_public' => false]), $contact));
    }

    public function test_private_flow_allows_authenticated_contact(): void
    {
        $policy  = new FlowAccessPolicy();
        $contact = Contact::make(['is_authenticated' => true]);

        $this->assertTrue($policy->canStart(FlowDefinition::make(['is_public' => false]), $contact));
    }

    public function test_absent_is_public_fails_open_as_public(): void
    {
        $policy  = new FlowAccessPolicy();
        $contact = Contact::make(['is_authenticated' => false]);

        // Legacy definition with no is_public attribute set must remain startable.
        $this->assertTrue($policy->canStart(FlowDefinition::make(), $contact));
    }
}
