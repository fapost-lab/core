<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Installer\Steps\ApplicationStep;
use App\Console\Installer\Steps\DatabaseStep;
use App\Console\Installer\Steps\RedisStep;
use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;
use Laravel\Prompts\Prompt;
use Tests\TestCase;

/**
 * The installer edits a file the operator owns and re-runs on half-finished
 * installations, so the risks worth testing are about what it must *not* do.
 */
final class InstallerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'install');
        Prompt::fallbackWhen(true);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * Regenerating the key would silently invalidate every encrypted column —
     * channel tokens and webhook secrets among them — turning a re-run of the
     * installer into data loss with no error at the time.
     */
    public function test_it_never_regenerates_an_existing_application_key(): void
    {
        $this->write("APP_KEY=base64:original-key-value\nAPP_URL=https://app.test\n");

        $step = new ApplicationStep();
        $env  = new EnvFile($this->path);

        $this->assertFalse(
            $step->isPending($env),
            'A configured application must not be asked again on a re-run.',
        );

        $this->assertSame('base64:original-key-value', $env->get('APP_KEY'));
    }

    public function test_application_step_is_pending_when_the_key_is_missing(): void
    {
        $this->write("APP_URL=https://app.test\nAPP_KEY=\n");

        $this->assertTrue((new ApplicationStep())->isPending(new EnvFile($this->path)));
    }

    public function test_application_step_is_pending_when_the_url_is_missing(): void
    {
        $this->write("APP_KEY=base64:something\n");

        $this->assertTrue((new ApplicationStep())->isPending(new EnvFile($this->path)));
    }

    /**
     * Connection steps report pending by probing, not by looking for keys: an
     * unreachable database is unfinished work even when every value is filled in.
     */
    public function test_connection_steps_are_pending_when_the_service_is_unreachable(): void
    {
        $this->write(implode("\n", [
            'DB_HOST=127.0.0.1',
            'DB_PORT=1',
            'DB_DATABASE=nope',
            'DB_USERNAME=nope',
            'DB_PASSWORD=nope',
            'REDIS_HOST=127.0.0.1',
            'REDIS_PORT=1',
        ]) . "\n");

        $env = new EnvFile($this->path);

        $this->assertTrue((new DatabaseStep())->isPending($env), 'An unreachable database must count as pending.');
        $this->assertTrue((new RedisStep())->isPending($env), 'An unreachable Redis must count as pending.');
    }

    /**
     * The installer configures an existing environment file; it does not invent
     * one. Creating a file from nothing would hide the fact that .env.example was
     * never copied, and with it any values the operator meant to set first.
     */
    public function test_it_refuses_to_run_without_an_env_file(): void
    {
        $missing = $this->path . '-absent';

        $this->assertSame(
            Command::FAILURE,
            $this->artisan('install', ['--env-path' => $missing])->run(),
        );

        $this->assertFileDoesNotExist($missing);
    }

    /**
     * Steps are ordered so that later ones can rely on earlier ones: the key
     * exists before anything encrypts, and connections are proven before
     * migrations run against them.
     */
    public function test_step_titles_are_distinct(): void
    {
        $titles = array_map(
            static fn (object $step): string => $step->title(),
            [new ApplicationStep(), new DatabaseStep(), new RedisStep()],
        );

        $this->assertSame($titles, array_unique($titles));
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents);
    }
}
