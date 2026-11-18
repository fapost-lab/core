<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Concurrency;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\Exceptions\ContactlessSessionException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LockScopeTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidTriggerScopes(): array
    {
        return [
            'empty trigger'          => ['', '', ''],
            'trigger with a contact' => ['trigger-1', 'contact-1', ''],
            'trigger with assistant' => ['trigger-1', '', 'assistant-1'],
        ];
    }
    public function test_a_scope_without_a_contact_is_refused_instead_of_sharing_one_key_per_tenant(): void
    {
        $this->expectException(ContactlessSessionException::class);

        new LockScope('tenant-1', '', 'assistant-1');
    }

    public function test_a_scope_without_an_assistant_keeps_its_contact_key(): void
    {
        $this->assertSame('session_lock:tenant-1:contact-1:', (new LockScope('tenant-1', 'contact-1', ''))->key());
    }

    public function test_a_trigger_scope_has_a_key_of_its_own(): void
    {
        $this->assertSame('session_lock:tenant-1:trigger:trigger-1', LockScope::forTrigger('tenant-1', 'trigger-1')->key());
    }

    #[DataProvider('invalidTriggerScopes')]
    public function test_a_trigger_scope_names_only_its_trigger(string $triggerId, string $contactId, string $assistantId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LockScope('tenant-1', $contactId, $assistantId, $triggerId);
    }

    public function test_a_session_with_a_contact_keeps_its_conversation_lock_even_with_a_trigger(): void
    {
        $session = $this->flowSession(['contact_id' => 'contact-1', 'assistant_id' => 'assistant-1', 'trigger_id' => 'trigger-1']);

        $this->assertSame('session_lock:tenant-1:contact-1:assistant-1', LockScope::ofSession($session)->key());
    }

    public function test_a_contactless_session_takes_its_trigger_lock(): void
    {
        $session = $this->flowSession(['contact_id' => null, 'assistant_id' => null, 'trigger_id' => 'trigger-1']);

        $this->assertSame('session_lock:tenant-1:trigger:trigger-1', LockScope::ofSession($session)->key());
    }

    public function test_a_contactless_session_without_a_trigger_has_no_lock(): void
    {
        $this->expectException(ContactlessSessionException::class);

        LockScope::ofSession($this->flowSession(['contact_id' => null, 'assistant_id' => null, 'trigger_id' => null]));
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private function flowSession(array $attributes): FlowSession
    {
        $session = new FlowSession();
        $session->setRawAttributes(['tenant_id' => 'tenant-1', ...$attributes]);

        return $session;
    }
}
