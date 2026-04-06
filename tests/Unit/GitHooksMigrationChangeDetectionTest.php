<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitHooksMigrationChangeDetectionTest extends TestCase
{
    public function test_migration_change_script_warns_for_landlord_and_tenant_changes(): void
    {
        $repository = $this->createTemporaryGitRepository();

        $this->runGit($repository, ['checkout', '-b', 'feature/migrations']);
        file_put_contents(
            $repository . '/database/migrations/landlord/2026_05_01_000001_add_flag.php',
            "<?php\n",
        );
        file_put_contents(
            $repository . '/database/migrations/tenant/2026_05_01_000002_add_flag.php',
            "<?php\n",
        );

        $this->runGit($repository, ['add', '.']);
        $this->runGit($repository, ['commit', '-m', 'Add migration files']);

        $previousRef = mb_trim($this->runGit($repository, ['rev-parse', 'main']));
        $currentRef  = mb_trim($this->runGit($repository, ['rev-parse', 'HEAD']));

        $process = new Process(
            ['sh', $this->repositoryPath('tools/git-hooks/check-migration-changes.sh'), $previousRef, $currentRef],
            $repository,
        );

        $process->mustRun();

        $output = $process->getOutput();

        self::assertStringContainsString('Warning: landlord migrations changed.', $output);
        self::assertStringContainsString('Warning: tenant migrations changed.', $output);
        self::assertStringContainsString('php artisan migrate:smart', $output);
    }

    public function test_post_checkout_wrapper_detects_removed_tenant_migration_on_branch_switch(): void
    {
        $repository = $this->createTemporaryGitRepository();

        $this->runGit($repository, ['checkout', '-b', 'feature/remove-tenant-migration']);
        $this->runGit($repository, ['rm', 'database/migrations/tenant/2026_01_01_000001_create_items_table.php']);
        $this->runGit($repository, ['commit', '-m', 'Remove tenant migration']);

        $previousRef = mb_trim($this->runGit($repository, ['rev-parse', 'main']));
        $currentRef  = mb_trim($this->runGit($repository, ['rev-parse', 'HEAD']));

        $process = new Process(
            ['sh', $this->repositoryPath('tools/git-hooks/post-checkout'), $previousRef, $currentRef, '1'],
            $repository,
        );

        $process->mustRun();

        $output = $process->getOutput();

        self::assertStringContainsString('Warning: tenant migrations changed.', $output);
        self::assertStringNotContainsString('Warning: landlord migrations changed.', $output);
        self::assertStringContainsString('php artisan migrate:smart', $output);
    }

    private function repositoryPath(string $path = ''): string
    {
        $repositoryRoot = dirname(__DIR__, 2);

        if ('' === $path) {
            return $repositoryRoot;
        }

        return $repositoryRoot . DIRECTORY_SEPARATOR . $path;
    }

    private function createTemporaryGitRepository(): string
    {
        $repository = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-migration-hooks-' . bin2hex(random_bytes(8));

        mkdir($repository, 0777, true);
        mkdir($repository . '/database/migrations/landlord', 0777, true);
        mkdir($repository . '/database/migrations/tenant', 0777, true);

        file_put_contents(
            $repository . '/database/migrations/landlord/2026_01_01_000001_create_landlord_table.php',
            "<?php\n",
        );
        file_put_contents(
            $repository . '/database/migrations/tenant/2026_01_01_000001_create_items_table.php',
            "<?php\n",
        );

        $this->runGit($repository, ['init', '-b', 'main']);
        $this->runGit($repository, ['config', 'user.name', 'Codex Test']);
        $this->runGit($repository, ['config', 'user.email', 'codex@example.test']);
        $this->runGit($repository, ['add', '.']);
        $this->runGit($repository, ['commit', '-m', 'Initial commit']);

        return $repository;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runGit(string $repository, array $arguments): string
    {
        $process = new Process(array_merge(['git'], $arguments), $repository);

        $process->mustRun();

        return $process->getOutput();
    }
}
