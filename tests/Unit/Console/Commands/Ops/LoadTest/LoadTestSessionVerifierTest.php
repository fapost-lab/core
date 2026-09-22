<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestSessionVerifier;
use Tests\TestCase;

final class LoadTestSessionVerifierTest extends TestCase
{
    private LoadTestSessionVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new LoadTestSessionVerifier();
    }

    public function test_matching_codes_are_counted_ok(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 2, 'code' => 'LT-A-0002-BBBB'],
        ];

        $report = $this->verifier->verify($expected, [
            1 => 'LT-A-0001-AAAA',
            2 => 'LT-A-0002-BBBB',
        ]);

        self::assertSame(2, $report->total);
        self::assertSame(2, $report->ok);
        self::assertSame(0, $report->dropped);
        self::assertFalse($report->hasLeaks());
        self::assertFalse($report->hasCorruption());
        self::assertSame(0.0, $report->droppedRatio());
    }

    public function test_missing_code_is_counted_as_dropped_not_a_failure(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
        ];

        $report = $this->verifier->verify($expected, [1 => null]);

        self::assertSame(1, $report->dropped);
        self::assertSame(0, $report->ok);
        self::assertFalse($report->hasLeaks());
        self::assertFalse($report->hasCorruption());
        self::assertSame(1.0, $report->droppedRatio());
    }

    public function test_empty_string_code_is_also_counted_as_dropped(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
        ];

        $report = $this->verifier->verify($expected, [1 => '']);

        self::assertSame(1, $report->dropped);
    }

    public function test_another_contacts_code_is_flagged_as_a_leak(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 2, 'code' => 'LT-A-0002-BBBB'],
        ];

        // Contact 1 ended up holding contact 2's code — cross-contact leak.
        $report = $this->verifier->verify($expected, [
            1 => 'LT-A-0002-BBBB',
            2 => 'LT-A-0002-BBBB',
        ]);

        self::assertTrue($report->hasLeaks());
        self::assertCount(1, $report->leaks);
        self::assertSame(1, $report->leaks[0]['chat_id']);
        self::assertSame('LT-A-0002-BBBB', $report->leaks[0]['actual_code']);
        self::assertSame(2, $report->leaks[0]['leaked_from_chat_id']);
    }

    public function test_another_tenants_code_is_flagged_as_a_leak(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
            ['tenant_slug' => 'loadtest-b', 'chat_id' => 2, 'code' => 'LT-B-0001-CCCC'],
        ];

        $report = $this->verifier->verify($expected, [
            1 => 'LT-B-0001-CCCC',
            2 => 'LT-B-0001-CCCC',
        ]);

        self::assertTrue($report->hasLeaks());
        self::assertSame('loadtest-b', $report->leaks[0]['leaked_from_tenant']);
    }

    public function test_a_value_matching_no_known_code_is_corrupted_not_a_leak(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
        ];

        $report = $this->verifier->verify($expected, [1 => 'garbage-value']);

        self::assertFalse($report->hasLeaks());
        self::assertTrue($report->hasCorruption());
        self::assertSame('garbage-value', $report->corrupted[0]['actual_code']);
    }

    public function test_stub_messages_carrying_another_chats_code_are_leaks(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 2, 'code' => 'LT-A-0002-BBBB'],
        ];

        $stubMessages = [
            ['chat_id' => 1, 'text' => 'Load test accepted: LT-A-0001-AAAA'],
            ['chat_id' => 2, 'text' => 'Load test accepted: LT-A-0001-AAAA'], // wrong contact's code
        ];

        $leaks = $this->verifier->verifyStubMessages($expected, $stubMessages);

        self::assertCount(1, $leaks);
        self::assertSame(2, $leaks[0]['chat_id']);
        self::assertSame('LT-A-0001-AAAA', $leaks[0]['contains_code']);
        self::assertSame(1, $leaks[0]['code_owner_chat_id']);
    }

    public function test_stub_messages_matching_their_own_chat_are_not_leaks(): void
    {
        $expected = [
            ['tenant_slug' => 'loadtest-a', 'chat_id' => 1, 'code' => 'LT-A-0001-AAAA'],
        ];

        $stubMessages = [
            ['chat_id' => 1, 'text' => 'Load test accepted: LT-A-0001-AAAA'],
            ['chat_id' => 1, 'text' => 'Load test: reply with your code.'],
        ];

        self::assertSame([], $this->verifier->verifyStubMessages($expected, $stubMessages));
    }
}
