<?php

declare(strict_types=1);

/*
| Only what differs from Laravel Boost's defaults (vendor/laravel/boost/config/boost.php);
| the package config is merged underneath.
*/

return [

    /*
    | Claude Code reads AGENTS.md through CLAUDE.md (`@AGENTS.md`). Writing the
    | Boost guidelines into CLAUDE.md as well would load them twice, so they go
    | into the one block in AGENTS.md that Codex already uses.
    */
    'agents' => [
        'claude_code' => [
            'guidelines_path' => 'AGENTS.md',
        ],
    ],

];
