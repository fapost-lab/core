<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\State\Writers\ContactWriter;
use App\Infrastructure\Flow\ConnectionAfterCommitDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SplFileInfo;

/**
 * No Flow service captures a database connection when it is built (`conventions/domain-code.md`): it takes
 * `ConnectionResolverInterface` and asks for the connection when it writes. A queue job resolves its `handle()`
 * dependencies before `TenantSwitcher::runForTenant()`, so a captured connection is the worker's default, not the
 * tenant's, whenever the tenant connection is another one. The per-class tests with `RecordingConnectionResolver`
 * cannot see such a capture, since an autowired `ConnectionInterface` comes from `db.connection`, not the resolver.
 */
final class FlowConnectionInjectionTest extends TestCase
{
    /**
     * Built by the engine for one node step, with the connection the engine resolved for that step.
     *
     * @var list<class-string>
     */
    private const array ALLOWED = [ContactWriter::class];

    /**
     * @var list<class-string>
     */
    private const array CAPTURED_TYPES = [ConnectionInterface::class, Connection::class, DatabaseManager::class];

    public function test_no_flow_service_takes_a_connection_in_its_constructor(): void
    {
        $offenders = [];

        foreach ([...$this->flowClasses(), ConnectionAfterCommitDispatcher::class] as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            if (null === $constructor || in_array($class, self::ALLOWED, true)) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && in_array($type->getName(), self::CAPTURED_TYPES, true)) {
                    $offenders[] = "{$class}::\${$parameter->getName()}";
                }
            }
        }

        $this->assertSame([], $offenders, 'Take ConnectionResolverInterface and resolve the connection when writing.');
    }

    /**
     * @return list<class-string>
     */
    private function flowClasses(): array
    {
        $root    = dirname(__DIR__, 4) . '/app/Domains/Flow';
        $classes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $relative = Str::after($file->getPathname(), $root . '/');
            $class    = 'App\\Domains\\Flow\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        $this->assertNotEmpty($classes);

        return $classes;
    }
}
