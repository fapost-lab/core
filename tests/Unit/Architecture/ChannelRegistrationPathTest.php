<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A channel reaches the {@see \App\Domains\Channels\ChannelRegistry} through a registrar only, a built-in one's or a
 * connector's. The tag that once carried built-in definitions and the internal adapter contract that went with it are
 * gone for good; a second way in would let a channel skip the shared registration checks.
 *
 * PHPat selects classes, not container tag strings, so this scans the source.
 */
final class ChannelRegistrationPathTest extends TestCase
{
    public function test_no_code_tags_a_channel_integration(): void
    {
        $offenders = $this->filesMentioning('channels.integration');

        $this->assertSame([], $offenders, 'A channel is registered through a registrar, not tagged `channels.integration`.');
    }

    public function test_the_internal_adapter_contract_is_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Domains/Webhook/Contracts/ChannelAdapterInterface.php'));
        $this->assertSame(
            [],
            $this->filesMentioning('App\\Domains\\Webhook\\Contracts\\ChannelAdapterInterface'),
            'An inbound adapter implements Fapost\Foundation\Channel\ChannelInboundAdapterInterface.',
        );
    }

    /**
     * @return list<string> paths under app/ whose source contains the needle
     */
    private function filesMentioning(string $needle): array
    {
        $found    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ('php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
