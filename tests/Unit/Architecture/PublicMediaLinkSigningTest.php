<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Only {@see \App\Domains\Media\Services\PublicMediaLinkIssuer} mints public media links, i.e. calls `->sign(` on the
 * signer. The resolver may depend on the signer (PHPat allows it) but only to verify.
 */
final class PublicMediaLinkSigningTest extends TestCase
{
    private const string ISSUER = 'app/Domains/Media/Services/PublicMediaLinkIssuer.php';

    public function test_only_the_issuer_signs(): void
    {
        $signing = [];

        foreach (Finder::create()->files()->name('*.php')->in(base_path('app')) as $file) {
            $path = 'app/' . str_replace('\\', '/', $file->getRelativePathname());

            if (1 === preg_match('/->sign\(/', (string)file_get_contents($file->getRealPath()))) {
                $signing[] = $path;
            }
        }

        $this->assertSame([self::ISSUER], $signing, 'Public media links are signed by PublicMediaLinkIssuer only.');
    }
}
