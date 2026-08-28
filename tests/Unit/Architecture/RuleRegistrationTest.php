<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * PHPat only executes rule classes that are registered as services tagged
 * `phpat.test` in phpstan.neon — a class dropped into tests/Architecture without
 * that entry is silently never run, and its rule reports success forever.
 *
 * This test fails the moment those two lists drift apart.
 */
final class RuleRegistrationTest extends TestCase
{
    public function test_every_architecture_rule_class_is_registered_in_phpstan_config(): void
    {
        $declared   = $this->declaredRuleClasses();
        $registered = $this->registeredRuleClasses();

        $this->assertNotEmpty($declared, 'Expected at least one rule class in tests/Architecture.');

        $this->assertSame(
            $declared,
            $registered,
            'tests/Architecture and the `phpat.test` services in phpstan.neon must list the same classes. '
            . 'Unregistered rules never run.',
        );
    }

    /**
     * @return list<string>
     */
    private function declaredRuleClasses(): array
    {
        $classes = [];

        foreach (glob(base_path('tests/Architecture/*.php')) ?: [] as $file) {
            $classes[] = 'Tests\\Architecture\\' . basename($file, '.php');
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function registeredRuleClasses(): array
    {
        $config = (string)file_get_contents(base_path('phpstan.neon'));

        preg_match_all(
            '/class:\s*(Tests\\\\Architecture\\\\\w+)\s*\n\s*tags:\s*\n\s*-\s*phpat\.test/',
            $config,
            $matches,
        );

        $classes = $matches[1];
        sort($classes);

        return $classes;
    }
}
