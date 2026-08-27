<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitHooksInstallationTest extends TestCase
{
    public function test_composer_scripts_install_git_hooks_for_developers(): void
    {
        $composerConfiguration = json_decode(
            (string) file_get_contents($this->repositoryPath('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            '@php tools/git-hooks/install.php',
            $composerConfiguration['scripts']['hooks:install'],
        );
        self::assertContains(
            '@hooks:install',
            $composerConfiguration['scripts']['post-install-cmd'],
        );
        self::assertContains(
            '@hooks:install',
            $composerConfiguration['scripts']['post-update-cmd'],
        );
    }

    public function test_pre_commit_hook_runs_project_quality_gates(): void
    {
        $hookContents         = (string) file_get_contents($this->repositoryPath('tools/git-hooks/pre-commit'));
        $postMergeContents    = (string) file_get_contents($this->repositoryPath('tools/git-hooks/post-merge'));
        $postCheckoutContents = (string) file_get_contents($this->repositoryPath('tools/git-hooks/post-checkout'));

        self::assertStringContainsString(
            "vendor/bin/pint --dirty --format agent",
            $hookContents,
        );
        self::assertStringContainsString(
            "php artisan test --compact --testsuite=Unit",
            $hookContents,
        );
        self::assertStringContainsString(
            "php artisan test --compact --testsuite=Feature",
            $hookContents,
        );
        self::assertStringContainsString(
            "vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M --no-interaction",
            $hookContents,
        );
        self::assertStringContainsString(
            'check-migration-changes.sh',
            $postMergeContents,
        );
        self::assertStringContainsString(
            'check-migration-changes.sh',
            $postCheckoutContents,
        );
    }

    public function test_installer_configures_git_hooks_path_in_repository(): void
    {
        $temporaryRepository = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fapost-hooks-' . bin2hex(random_bytes(8));

        mkdir($temporaryRepository, 0777, true);
        mkdir($temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks', 0777, true);

        $gitInitProcess = new Process(['git', 'init'], $temporaryRepository);
        $gitInitProcess->mustRun();

        copy(
            $this->repositoryPath('tools/git-hooks/install.php'),
            $temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks' . DIRECTORY_SEPARATOR . 'install.php',
        );
        copy(
            $this->repositoryPath('tools/git-hooks/pre-commit'),
            $temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks' . DIRECTORY_SEPARATOR . 'pre-commit',
        );
        copy(
            $this->repositoryPath('tools/git-hooks/post-merge'),
            $temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks' . DIRECTORY_SEPARATOR . 'post-merge',
        );
        copy(
            $this->repositoryPath('tools/git-hooks/post-checkout'),
            $temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks' . DIRECTORY_SEPARATOR . 'post-checkout',
        );
        copy(
            $this->repositoryPath('tools/git-hooks/check-migration-changes.sh'),
            $temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks' . DIRECTORY_SEPARATOR . 'check-migration-changes.sh',
        );

        $process = new Process([
            PHP_BINARY,
            $temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks' . DIRECTORY_SEPARATOR . 'install.php',
        ], $temporaryRepository);

        $process->mustRun();

        $hooksPathProcess = new Process(
            ['git', 'config', '--get', 'core.hooksPath'],
            $temporaryRepository,
        );

        $hooksPathProcess->mustRun();

        self::assertSame(
            realpath($temporaryRepository . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'git-hooks'),
            mb_trim($hooksPathProcess->getOutput()),
        );
    }

    private function repositoryPath(string $path = ''): string
    {
        $repositoryRoot = dirname(__DIR__, 3);

        if ('' === $path) {
            return $repositoryRoot;
        }

        return $repositoryRoot . DIRECTORY_SEPARATOR . $path;
    }
}
