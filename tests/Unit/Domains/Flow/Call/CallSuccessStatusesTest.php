<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call;

use App\Domains\Flow\Call\CallSuccessStatuses;
use Fapost\Foundation\Flow\Call\CallResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallSuccessStatusesTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformed(): array
    {
        return [
            'not a list'     => ['2xx'],
            'a map'          => [['ok' => 200]],
            'below range'    => [[99]],
            'above range'    => [[600]],
            'unknown class'  => [['6xx']],
            'reversed range' => [['300-200']],
            'words'          => [['success']],
            'float'          => [[200.5]],
            'nested'         => [[[200]]],
            'too many'       => [array_fill(0, 21, 200)],
        ];
    }
    public function test_numbers_classes_and_ranges_are_accepted(): void
    {
        $statuses = CallSuccessStatuses::fromConfig([201, '404', '3xx', '500-503', ' 2XX ']);

        $this->assertInstanceOf(CallSuccessStatuses::class, $statuses);

        foreach ([200, 201, 299, 301, 404, 500, 503] as $status) {
            $this->assertTrue($statuses->accepts($status), (string) $status);
        }

        foreach ([100, 400, 403, 504, 599] as $status) {
            $this->assertFalse($statuses->accepts($status), (string) $status);
        }
    }

    public function test_an_absent_or_empty_list_means_no_policy(): void
    {
        $this->assertNull(CallSuccessStatuses::fromConfig(null));
        $this->assertNull(CallSuccessStatuses::fromConfig([]));
        $this->assertNull(CallSuccessStatuses::problem(null));
        $this->assertNull(CallSuccessStatuses::problem([]));
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_list_is_a_problem_and_no_policy(mixed $raw): void
    {
        $this->assertNotNull(CallSuccessStatuses::problem($raw));
        $this->assertNull(CallSuccessStatuses::fromConfig($raw));
    }

    public function test_a_listed_status_turns_a_transport_error_into_a_success_and_keeps_the_response(): void
    {
        $statuses = CallSuccessStatuses::fromConfig([404]);
        $result   = $statuses->judge(CallResult::error('http_4xx', ['e' => 1], ['status_code' => 404]));

        $this->assertTrue($result->success);
        $this->assertNull($result->errorCode);
        $this->assertSame(['e' => 1], $result->payload);
        $this->assertSame(['status_code' => 404], $result->metadata);
    }

    public function test_an_unlisted_status_turns_a_success_into_an_error_with_the_http_code(): void
    {
        $statuses = CallSuccessStatuses::fromConfig([201]);

        $this->assertSame('http_other', $statuses->judge(CallResult::ok(null, ['status_code' => 200]))->errorCode);
        $this->assertSame('http_5xx', $statuses->judge(CallResult::ok(null, ['status_code' => 503]))->errorCode);
        $this->assertFalse($statuses->judge(CallResult::ok(null, ['status_code' => 200]))->success);
    }

    public function test_a_result_without_a_status_keeps_the_transport_verdict(): void
    {
        $statuses = CallSuccessStatuses::fromConfig([201]);

        $this->assertTrue($statuses->judge(CallResult::ok(['x' => 1]))->success);
        $this->assertSame('transport_failure', $statuses->judge(CallResult::error('transport_failure'))->errorCode);
    }
}
