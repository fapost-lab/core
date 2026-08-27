<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlowDraft>
 */
final class FlowDraftFactory extends Factory
{
    protected $model = FlowDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'     => '00000000-0000-0000-0000-000000000001',
            'flow_id'       => (string) Str::uuid(),
            'draft_version' => 1,
            'name'          => 'Draft flow',
            'nodes'         => [],
        ];
    }

    public function withUnconnectedOutput(): self
    {
        return $this->state(fn (): array => [
            'nodes' => [
                [
                    'id'      => 'condition_1',
                    'type'    => 'branch',
                    'version' => 1,
                    'config'  => [
                        'check' => 'flow.answer',
                        'rules' => [
                            ['operator' => 'eq', 'value' => 'yes', 'handle' => 'yes'],
                        ],
                    ],
                    'outputs' => [
                        'yes' => ['next' => 'missing-node'],
                    ],
                ],
            ],
        ]);
    }
}
