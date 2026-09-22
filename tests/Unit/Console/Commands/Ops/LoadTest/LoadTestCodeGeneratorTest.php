<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Ops\LoadTest;

use App\Console\Commands\Ops\LoadTest\Support\LoadTestCodeGenerator;
use Tests\TestCase;

final class LoadTestCodeGeneratorTest extends TestCase
{
    public function test_chat_ids_are_unique_across_tenants_and_contacts(): void
    {
        $chatIds = [];

        for ($tenant = 1; $tenant <= 3; $tenant++) {
            for ($contact = 1; $contact <= 5; $contact++) {
                $chatIds[] = LoadTestCodeGenerator::chatId($tenant, $contact);
            }
        }

        self::assertCount(15, array_unique($chatIds));
    }

    public function test_chat_id_is_deterministic_for_the_same_indices(): void
    {
        self::assertSame(
            LoadTestCodeGenerator::chatId(2, 7),
            LoadTestCodeGenerator::chatId(2, 7),
        );
    }

    public function test_code_embeds_the_tenant_slug_uppercased(): void
    {
        $code = LoadTestCodeGenerator::code('loadtest-abc123-1', 42);

        self::assertStringContainsString('LOADTEST-ABC123-1', $code);
        self::assertStringContainsString('0042', $code);
    }

    public function test_codes_for_the_same_contact_are_not_reused_across_calls(): void
    {
        // The random suffix must make repeated calls distinguishable, so a
        // leak detector can never mistake "generated twice" for "leaked".
        $first  = LoadTestCodeGenerator::code('loadtest-abc123-1', 1);
        $second = LoadTestCodeGenerator::code('loadtest-abc123-1', 1);

        self::assertNotSame($first, $second);
    }
}
