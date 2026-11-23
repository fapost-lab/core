<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * The flow builder layout a staff user chose. Kept per person in `users.builder_mode`; `null` there reads as Tree.
 */
enum BuilderMode: string
{
    case Tree  = 'tree';
    case Graph = 'graph';
}
