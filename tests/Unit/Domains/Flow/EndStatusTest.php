<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EndStatusTest extends TestCase
{
    /**
     * @return array<string, array{mixed, FlowSessionStatus|null, EndStatus}>
     */
    public static function childrenThatAreOver(): array
    {
        return [
            'an end node status wins over the status'  => ['cancelled', FlowSessionStatus::Completed, EndStatus::Cancelled],
            'an end node status of a failed child'     => ['success', FlowSessionStatus::Failed, EndStatus::Success],
            'an end status that is no case is success' => ['bogus', FlowSessionStatus::Failed, EndStatus::Success],
            'an empty end status reads the status'     => ['', FlowSessionStatus::Failed, EndStatus::Failed],
            'a child that ran off its graph'           => [null, FlowSessionStatus::Completed, EndStatus::Success],
            'an ended child'                           => [null, FlowSessionStatus::Ended, EndStatus::Success],
            'a failed child'                           => [null, FlowSessionStatus::Failed, EndStatus::Failed],
            'a child the sweeper expired'              => [null, FlowSessionStatus::Expired, EndStatus::Failed],
            'a cancelled child'                        => [null, FlowSessionStatus::Cancelled, EndStatus::Cancelled],
            'a child a person stopped'                 => [null, FlowSessionStatus::TerminatedByUser, EndStatus::Cancelled],
            'no status'                                => [null, null, EndStatus::Success],
        ];
    }

    #[DataProvider('childrenThatAreOver')]
    public function test_handed_back_by_maps_a_child_to_the_end_its_parent_routes_on(mixed $endStatus, ?FlowSessionStatus $status, EndStatus $expected): void
    {
        $this->assertSame($expected, EndStatus::handedBackBy($endStatus, $status));
    }
}
