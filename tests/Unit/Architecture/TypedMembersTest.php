<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use ReflectionClass;
use ReflectionProperty;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every own property and own class constant is typed. The only exception is a
 * property overriding an untyped property of a parent class (typing it is a
 * fatal error in PHP); such a property must document its type with `@var`.
 */
final class TypedMembersTest extends TestCase
{
    public function test_own_properties_and_constants_are_typed(): void
    {
        $violations = [];

        foreach ($this->classesUnderApp() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if ($this->declaredByTrait($reflection, $property->getName())) {
                    continue;
                }

                if ($property->hasType()) {
                    continue;
                }

                if ($this->parentDeclaresUntyped($reflection, $property->getName())) {
                    if (! preg_match('/@var\s+\S/', (string) $property->getDocComment())) {
                        $violations[] = "{$class}::\${$property->getName()} overrides an untyped parent property but has no @var";
                    }

                    continue;
                }

                $violations[] = "{$class}::\${$property->getName()} has no type";
            }

            foreach ($reflection->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $class || $constant->isEnumCase()) {
                    continue;
                }

                if ($this->declaredByTrait($reflection, $constant->getName(), true)) {
                    continue;
                }

                if (! $constant->hasType()) {
                    $violations[] = "{$class}::{$constant->getName()} constant has no type";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Untyped own properties/constants:\n".implode("\n", $violations)
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function parentDeclaresUntyped(ReflectionClass $class, string $name): bool
    {
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if (! $parent->hasProperty($name)) {
                continue;
            }

            $property = $parent->getProperty($name);

            if ($property->isPrivate()) {
                continue;
            }

            return ! $property->hasType();
        }

        return false;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function declaredByTrait(ReflectionClass $class, string $name, bool $constant = false): bool
    {
        foreach ($class->getTraits() as $trait) {
            $has = $constant ? $trait->hasConstant($name) : $trait->hasProperty($name);

            if ($has || $this->declaredByTrait($trait, $name, $constant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function classesUnderApp(): array
    {
        $base = app_path();
        $classes = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($base) + 1, -4);
            $class = 'App\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
