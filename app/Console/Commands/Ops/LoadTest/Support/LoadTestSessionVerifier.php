<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

/**
 * Pure comparison logic for `loadtest:verify`: given what every contact was
 * expected to end up with and what was actually observed, classifies each
 * contact as ok / dropped / leaked / corrupted.
 *
 * Kept free of Eloquent/Redis/HTTP so the classification rules — the part
 * that actually encodes "what counts as a leak" — can be unit tested without
 * a database or a running stub.
 */
final class LoadTestSessionVerifier
{
    /**
     * @param  list<array{tenant_slug: string, chat_id: int, code: string}>  $expected
     * @param  array<int, string|null>  $actualCodesByChatId  chat_id => the contact's stored
     *         `loadtest_code` attribute, or null/empty when the contact never completed the input step.
     */
    public function verify(array $expected, array $actualCodesByChatId): LoadTestVerificationReport
    {
        $knownCodes = [];
        foreach ($expected as $row) {
            $knownCodes[$row['code']] = $row;
        }

        $ok        = 0;
        $dropped   = 0;
        $leaks     = [];
        $corrupted = [];

        foreach ($expected as $row) {
            $actual = $actualCodesByChatId[$row['chat_id']] ?? null;

            if (null === $actual || '' === $actual) {
                $dropped++;

                continue;
            }

            if ($actual === $row['code']) {
                $ok++;

                continue;
            }

            $owner = $knownCodes[$actual] ?? null;

            if (null !== $owner) {
                $leaks[] = [
                    'chat_id'             => $row['chat_id'],
                    'tenant_slug'         => $row['tenant_slug'],
                    'expected_code'       => $row['code'],
                    'actual_code'         => $actual,
                    'leaked_from_tenant'  => $owner['tenant_slug'],
                    'leaked_from_chat_id' => $owner['chat_id'],
                ];

                continue;
            }

            $corrupted[] = [
                'chat_id'       => $row['chat_id'],
                'tenant_slug'   => $row['tenant_slug'],
                'expected_code' => $row['code'],
                'actual_code'   => $actual,
            ];
        }

        return new LoadTestVerificationReport(
            total: count($expected),
            ok: $ok,
            dropped: $dropped,
            leaks: $leaks,
            corrupted: $corrupted,
        );
    }

    /**
     * Scans every outbound stub message for a code that belongs to a
     * *different* chat than the one the message was actually sent to — the
     * transcript-level counterpart of {@see verify()}'s attribute check.
     *
     * @param  list<array{tenant_slug: string, chat_id: int, code: string}>  $expected
     * @param  list<array{chat_id: int, text: string}>  $stubMessages
     *
     * @return list<array<string, mixed>>
     */
    public function verifyStubMessages(array $expected, array $stubMessages): array
    {
        $codeToOwner = [];
        foreach ($expected as $row) {
            $codeToOwner[$row['code']] = $row;
        }

        if ([] === $codeToOwner) {
            return [];
        }

        $leaks = [];

        foreach ($stubMessages as $message) {
            foreach ($codeToOwner as $code => $owner) {
                if ('' === $code || ! str_contains($message['text'], $code)) {
                    continue;
                }

                if ($owner['chat_id'] === $message['chat_id']) {
                    continue;
                }

                $leaks[] = [
                    'chat_id'            => $message['chat_id'],
                    'contains_code'      => $code,
                    'code_owner_tenant'  => $owner['tenant_slug'],
                    'code_owner_chat_id' => $owner['chat_id'],
                ];
            }
        }

        return $leaks;
    }
}
