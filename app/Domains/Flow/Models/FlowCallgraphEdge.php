<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use Fapost\Support\Models\BaseModel;

/**
 * Reverse-index row of a `subflow.flow_id` reference that exists inside a
 * specific (caller_flow_id, caller_definition_id) pair. Populated atomically
 * during flow_definition publish; consumed by the subflow cycle/depth
 * validator. See ADR Subflow Composition.
 *
 * @property string $caller_flow_id
 * @property string $callee_flow_id
 * @property string $caller_definition_id
 */
final class FlowCallgraphEdge extends BaseModel
{
    public $incrementing = false;

    public $timestamps = false;

    /** Composite primary key — Eloquent has no first-class support, fall back to standard table semantics. */
    protected $primaryKey = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'caller_flow_id',
        'callee_flow_id',
        'caller_definition_id',
    ];

    public function getTable(): string
    {
        return 'flow_callgraph_edges';
    }
}
