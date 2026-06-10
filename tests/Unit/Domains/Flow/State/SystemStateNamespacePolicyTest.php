<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\State;

use App\Domains\Flow\Exceptions\StateNamespaceViolationException;
use App\Domains\Flow\State\SystemStateNamespacePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SystemStateNamespacePolicyTest extends TestCase
{
    private SystemStateNamespacePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SystemStateNamespacePolicy();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedWrites(): iterable
    {
        yield 'any handler writes flow.*'        => ['custom_solution_node', 'flow.answer'];
        yield 'any handler writes call.*'        => ['custom_solution_node', 'call.response.body'];
        yield 'send_message writes system.*'     => ['send_message', 'system.sent_messages.n1'];
        yield 'input writes system retry'        => ['input', 'system.input.n1.retry_count'];
        yield 'delay writes system schedule'     => ['delay', 'system.delay.n1.scheduled_at'];
        yield 'notify writes system marker'      => ['notify', 'system.staff_notified.n1'];
        yield 'set_tag writes system marker'     => ['set_tag', 'system.set_tag.n1'];
        yield 'rag_query writes rag.*'           => ['rag_query', 'rag.answer'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenWrites(): iterable
    {
        yield 'foreign handler writes system.*' => ['custom_solution_node', 'system.language'];
        yield 'branch writes system.*'          => ['branch', 'system.sent_messages.n1'];
        yield 'foreign handler writes rag.*'    => ['send_message', 'rag.answer'];
        yield 'module namespace is read-only'   => ['custom_solution_node', 'module.hr.department'];
        yield 'contact projection not writable' => ['assign', 'contact.first_name'];
        yield 'unknown namespace'               => ['assign', 'admin.secret'];
    }

    #[DataProvider('allowedWrites')]
    public function test_allowed_writes_pass(string $nodeType, string $key): void
    {
        $this->policy->assertWriteAllowed($nodeType, $key);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('forbiddenWrites')]
    public function test_forbidden_writes_throw(string $nodeType, string $key): void
    {
        $this->expectException(StateNamespaceViolationException::class);

        $this->policy->assertWriteAllowed($nodeType, $key);
    }
}
