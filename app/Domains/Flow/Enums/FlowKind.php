<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * What a flow draft (and each of its published definitions) is. A `Template` is edited and published like a flow
 * but is never started on its own: a host flow places it as one `template` node, and publishing the host expands
 * that node into a copy of the template's active definition. Set when the draft is created, never changed.
 */
enum FlowKind: string
{
    case Flow     = 'flow';
    case Template = 'template';
}
