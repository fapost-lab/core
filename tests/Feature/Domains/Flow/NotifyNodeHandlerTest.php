<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Contact\Jobs\SendContactNotificationJob;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\NotifyNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class NotifyNodeHandlerTest extends TestCase
{
    public function test_staff_mode_dispatches_delivery_job_with_rendered_message_and_marks_state(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'channel' => 'in_app', 'message' => 'Help {{flow.topic}}']),
            ['flow' => ['topic' => 'billing']],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertTrue($result->stateChanges[SystemStateKeys::STAFF_NOTIFIED_PREFIX . '.node-notify']);

        Bus::assertDispatched(SendStaffNotificationJob::class, fn (SendStaffNotificationJob $job): bool => 'Help billing' === $job->message
                && ['in_app'] === $job->channels
                && 'assistant' === $job->targetConfig['target']
                && 'node-notify' === $job->nodeId);
    }

    public function test_staff_channel_all_expands_to_registered_transports(): void
    {
        Bus::fake();

        $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'channel' => 'all', 'message' => 'x']),
            [],
            $this->context(),
        );

        Bus::assertDispatched(SendStaffNotificationJob::class, fn (SendStaffNotificationJob $job): bool => in_array('in_app', $job->channels, true) && in_array('email', $job->channels, true));
    }

    public function test_staff_does_not_dispatch_when_already_marked(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'message' => 'x']),
            ['system' => ['staff_notified' => ['node-notify' => true]]],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame([], $result->stateChanges);
        Bus::assertNotDispatched(SendStaffNotificationJob::class);
    }

    public function test_staff_throws_on_empty_message(): void
    {
        Bus::fake();

        $this->expectException(InvalidNodeConfigException::class);

        $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'message' => '   ']),
            [],
            $this->context(),
        );
    }

    public function test_contacts_mode_dispatches_fan_out_job_and_marks_state(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node([
                'mode'           => 'contacts',
                'assistant_id'   => 'assistant-9',
                'contact_target' => 'tag',
                'tags'           => ['vip', 'lead'],
                'message'        => 'Promo {{flow.topic}}',
            ]),
            ['flow' => ['topic' => 'sale']],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('contacts', $result->metadata['mode']);
        $this->assertTrue($result->metadata['queued']);
        $this->assertSame('assistant-9', $result->metadata['assistant_id']);
        $this->assertEqualsCanonicalizing(['vip', 'lead'], $result->metadata['tags']);
        $this->assertTrue($result->stateChanges[SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . '.node-notify']);

        Bus::assertDispatched(SendContactNotificationJob::class, fn (SendContactNotificationJob $job): bool => 'assistant-9' === $job->assistantId
                && 'tag' === $job->contactTarget
                && ['vip', 'lead'] === $job->tags
                && 'Promo sale' === $job->message
                && 'node-notify' === $job->nodeId);
    }

    public function test_contacts_mode_does_not_dispatch_when_already_marked(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'message' => 'x']),
            ['system' => ['contacts_notified' => ['node-notify' => true]]],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame([], $result->stateChanges);
        Bus::assertNotDispatched(SendContactNotificationJob::class);
    }

    public function test_contacts_mode_throws_without_assistant(): void
    {
        Bus::fake();

        $this->expectException(InvalidNodeConfigException::class);

        $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => '', 'message' => 'Promo']),
            [],
            $this->context(),
        );
    }

    public function test_contacts_mode_throws_on_empty_message(): void
    {
        Bus::fake();

        $this->expectException(InvalidNodeConfigException::class);

        $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'message' => '   ']),
            [],
            $this->context(),
        );
    }

    private function handler(): NotifyNodeHandler
    {
        return new NotifyNodeHandler(
            app(Dispatcher::class),
            app(TemplateRenderer::class),
            app(StaffNotifierRegistry::class),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function node(array $config): array
    {
        return ['id' => 'node-notify', 'type' => 'notify', 'config' => $config];
    }

    private function context(): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: '00000000-0000-0000-0000-000000000001',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'node-notify',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
        );
    }
}
