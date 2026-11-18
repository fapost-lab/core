<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Concurrency;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\State\Exceptions\ContactlessSessionException;
use PHPUnit\Framework\TestCase;

final class LockScopeTest extends TestCase
{
    public function test_a_scope_without_a_contact_is_refused_instead_of_sharing_one_key_per_tenant(): void
    {
        $this->expectException(ContactlessSessionException::class);

        new LockScope('tenant-1', '', 'assistant-1');
    }

    public function test_a_scope_without_an_assistant_keeps_its_contact_key(): void
    {
        $this->assertSame('session_lock:tenant-1:contact-1:', (new LockScope('tenant-1', 'contact-1', ''))->key());
    }
}
