<?php

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__, 2);
$hooksPath      = __DIR__;
$hookFiles      = [
    $hooksPath . DIRECTORY_SEPARATOR . 'pre-commit',
    $hooksPath . DIRECTORY_SEPARATOR . 'post-merge',
    $hooksPath . DIRECTORY_SEPARATOR . 'post-checkout',
    $hooksPath . DIRECTORY_SEPARATOR . 'check-migration-changes.sh',
];

if ( ! is_dir($repositoryRoot . DIRECTORY_SEPARATOR . '.git')) {
    fwrite(STDOUT, "Skipping git hook installation: .git directory was not found.\n");

    exit(0);
}

foreach ($hookFiles as $hookFile) {
    if ( ! is_file($hookFile)) {
        fwrite(STDERR, "Unable to install git hooks: required file [{$hookFile}] was not found.\n");

        exit(1);
    }

    @chmod($hookFile, 0755);
}

$command = sprintf(
    'git -C %s config core.hooksPath %s 2>&1',
    escapeshellarg($repositoryRoot),
    escapeshellarg($hooksPath),
);

$output   = [];
$exitCode = 0;

exec($command, $output, $exitCode);

if (0 !== $exitCode) {
    fwrite(
        STDERR,
        "Unable to configure git hooks path.\n" . implode(PHP_EOL, $output) . PHP_EOL,
    );

    exit($exitCode);
}

fwrite(STDOUT, "Git hooks path configured: {$hooksPath}\n");
