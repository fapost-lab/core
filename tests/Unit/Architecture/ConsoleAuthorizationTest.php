<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Http\Controllers\Console\Auth\LoginController;
use App\Http\Controllers\Console\Auth\LogoutController;
use FilesystemIterator;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every public action of a console or admin controller authorizes.
 *
 * The console's stacks only prove who the user is and that the account may use the console; what the user
 * may do, and to which record, is each action's own check. This is a source scan, a safety net against forgetting the
 * call, not a proof that the right ability is asked. An action passes when its body contains a `Gate` ability check
 * (`Gate::authorize|allows|denies|check|inspect`), `->authorize(` (the controller's, a policy's, a user's),
 * `->can(` or `->cannot(`, or when it type-hints a {@see FormRequest} whose own `authorize()` does not simply
 * return true.
 *
 * Limits: a check inside a method the action calls is not seen (put it in the action, or in the FormRequest);
 * a comment or a string that spells the call satisfies the scan; the scan reads only the controller files under
 * `app/Http/Controllers/Console` and `app/Http/Controllers/Admin`. The sign-in and sign-out controllers are exempt:
 * they act for a visitor who has no abilities yet.
 */
final class ConsoleAuthorizationTest extends TestCase
{
    /**
     * Controllers that act before any ability exists.
     *
     * @var list<class-string>
     */
    private const array EXEMPT = [
        LoginController::class,
        LogoutController::class,
    ];

    private const string CHECK = '/Gate::(?:authorize|allows|denies|check|inspect)\(|->authorize\(|->can\(|->cannot\(/';

    public function test_every_public_action_of_a_console_or_admin_controller_authorizes(): void
    {
        $classes = $this->controllers();

        $this->assertContains(LoginController::class, $classes, 'The scan did not find the console controllers.');

        $this->assertSame([], $this->unauthorizedActions(array_diff($classes, self::EXEMPT)));
    }

    public function test_the_scan_flags_an_action_without_a_check_and_accepts_the_ways_to_authorize(): void
    {
        $this->assertSame(
            [AuthorizationFixtures\Unchecked::class . '::index'],
            $this->unauthorizedActions([
                AuthorizationFixtures\Unchecked::class,
                AuthorizationFixtures\WithGate::class,
                AuthorizationFixtures\WithAuthorize::class,
                AuthorizationFixtures\WithFormRequest::class,
            ]),
        );
    }

    public function test_a_form_request_that_always_allows_does_not_count(): void
    {
        $this->assertSame(
            [AuthorizationFixtures\WithOpenFormRequest::class . '::store'],
            $this->unauthorizedActions([AuthorizationFixtures\WithOpenFormRequest::class]),
        );
    }

    /**
     * @param  iterable<class-string>  $classes
     *
     * @return list<string>
     */
    private function unauthorizedActions(iterable $classes): array
    {
        $violations = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isStatic() || str_starts_with($method->getName(), '__') && '__invoke' !== $method->getName()) {
                    continue;
                }

                if (! $this->authorizes($method)) {
                    $violations[] = $class . '::' . $method->getName();
                }
            }
        }

        return $violations;
    }

    private function authorizes(ReflectionMethod $method): bool
    {
        return 1 === preg_match(self::CHECK, $this->source($method)) || $this->hasAuthorizingFormRequest($method);
    }

    private function hasAuthorizingFormRequest(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_subclass_of($type->getName(), FormRequest::class)) {
                continue;
            }

            $authorize = new ReflectionMethod($type->getName(), 'authorize');

            if (FormRequest::class !== $authorize->getDeclaringClass()->getName() && 1 !== preg_match('/return\s+true\s*;/', $this->source($authorize))) {
                return true;
            }
        }

        return false;
    }

    private function source(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        return implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /**
     * @return list<class-string>
     */
    private function controllers(): array
    {
        $root    = dirname(__DIR__, 3) . '/app/Http/Controllers';
        $classes = [];

        foreach (['Console', 'Admin'] as $directory) {
            if (! is_dir("{$root}/{$directory}")) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS)) as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }

                $relative = mb_substr($file->getPathname(), mb_strlen($root) + 1, -4);
                $class    = 'App\\Http\\Controllers\\' . str_replace('/', '\\', $relative);

                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }
}
