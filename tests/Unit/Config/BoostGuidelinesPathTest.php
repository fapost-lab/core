<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Laravel\Boost\Install\Agents\ClaudeCode;
use Tests\TestCase;

/**
 * Laravel Boost writes Claude Code's guidelines into AGENTS.md, the file
 * CLAUDE.md already imports, rather than into a second copy in CLAUDE.md.
 */
final class BoostGuidelinesPathTest extends TestCase
{
    public function test_claude_code_guidelines_go_into_agents_md(): void
    {
        $this->assertSame('AGENTS.md', $this->app->make(ClaudeCode::class)->guidelinesPath());
    }
}
