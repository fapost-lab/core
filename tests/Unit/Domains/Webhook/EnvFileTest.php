<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Webhook\Deployment\EnvFile;
use RuntimeException;
use Tests\TestCase;

/**
 * The installer edits a file the operator owns and has hand-tuned, so the risk
 * worth guarding against is not a wrong value but a lost one.
 */
final class EnvFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * Everything the installer was not asked to touch must survive byte for byte:
     * comments, blank lines, ordering and unrelated keys.
     */
    public function test_it_preserves_everything_it_was_not_asked_to_change(): void
    {
        $original = <<<'ENV'
            # Application
            APP_NAME="FAPost Core"
            APP_KEY=base64:secret

            # Database — do not touch
            DB_HOST=127.0.0.1
            DB_PASSWORD="p@ss word"
            ENV;

        $this->write($original);

        $this->file()->set(['GATEWAY_ADDR' => ':8080']);

        $result = $this->read();

        foreach (explode("\n", $original) as $line) {
            $this->assertStringContainsString($line, $result, "Lost line: {$line}");
        }

        $this->assertStringContainsString('GATEWAY_ADDR=:8080', $result);
    }

    public function test_it_updates_an_existing_key_in_place(): void
    {
        $this->write("APP_NAME=Old\nWEBHOOK_INGRESS_DRIVER=laravel\nAPP_URL=http://localhost");

        $this->file()->set(['WEBHOOK_INGRESS_DRIVER' => 'gateway']);

        $result = $this->read();

        $this->assertStringContainsString('WEBHOOK_INGRESS_DRIVER=gateway', $result);
        $this->assertStringNotContainsString('WEBHOOK_INGRESS_DRIVER=laravel', $result);
        $this->assertSame(
            1,
            substr_count($result, 'WEBHOOK_INGRESS_DRIVER='),
            'The key was duplicated instead of replaced.',
        );

        // Position matters: a key rewritten at the bottom loses the grouping the
        // operator arranged, and on a large file that reads as data loss.
        $this->assertLessThan(
            mb_strpos($result, 'APP_URL'),
            mb_strpos($result, 'WEBHOOK_INGRESS_DRIVER'),
            'The key moved instead of being updated where it was.',
        );
    }

    /**
     * A commented-out assignment is documentation, not configuration. Treating it
     * as the live key would leave the real one untouched and the setting ignored.
     */
    public function test_it_does_not_match_commented_assignments(): void
    {
        $this->write("# GATEWAY_ADDR=:9999\nGATEWAY_ADDR=:8080");

        $this->file()->set(['GATEWAY_ADDR' => ':7070']);

        $result = $this->read();

        $this->assertStringContainsString('# GATEWAY_ADDR=:9999', $result);
        $this->assertStringContainsString('GATEWAY_ADDR=:7070', $result);
        $this->assertStringNotContainsString("\nGATEWAY_ADDR=:8080", $result);
    }

    public function test_it_quotes_values_that_need_it(): void
    {
        $this->write('APP_NAME=x');

        $this->file()->set([
            'PLAIN'  => 'https://webhook.example.com',
            'SPACED' => '127.0.0.1, 10.0.0.5',
        ]);

        $result = $this->read();

        $this->assertStringContainsString('PLAIN=https://webhook.example.com', $result);
        $this->assertStringContainsString('SPACED="127.0.0.1, 10.0.0.5"', $result);
    }

    public function test_it_reads_values_ignoring_quotes(): void
    {
        $this->write("QUOTED=\"FAPost Core\"\nBARE=plain\nEMPTY=");

        $file = $this->file();

        $this->assertSame('FAPost Core', $file->get('QUOTED'));
        $this->assertSame('plain', $file->get('BARE'));
        $this->assertNull($file->get('EMPTY'));
        $this->assertNull($file->get('ABSENT'));
    }

    /**
     * A prefix match would make APP_URL rewrite APP_URL_EXTRA, or worse.
     */
    public function test_it_does_not_match_keys_by_prefix(): void
    {
        $this->write("APP_URL=http://a\nAPP_URL_EXTRA=keep");

        $this->file()->set(['APP_URL' => 'http://b']);

        $result = $this->read();

        $this->assertStringContainsString('APP_URL_EXTRA=keep', $result);
        $this->assertStringContainsString('APP_URL=http://b', $result);
    }

    public function test_it_refuses_to_write_a_missing_file(): void
    {
        unlink($this->path);

        $this->expectException(RuntimeException::class);

        $this->file()->set(['ANY' => 'value']);
    }

    private function file(): EnvFile
    {
        return new EnvFile($this->path);
    }

    private function write(string $contents): void
    {
        file_put_contents($this->path, $contents . "\n");
    }

    private function read(): string
    {
        return (string) file_get_contents($this->path);
    }
}
