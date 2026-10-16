<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Media\DTO\PublicMediaLinkClaims;
use App\Domains\Media\Services\PublicMediaLinkSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class PublicMediaLinkSignerTest extends TestCase
{
    private const string KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const string OTHER_KEY = 'base64:Hx4dHBsaGRgXFhUUExIREA8ODQwLCgkIBwYFBAMCAQA=';

    /**
     * @return array<string, array{PublicMediaLinkClaims}>
     */
    public static function tamperedClaims(): array
    {
        return [
            'tenant'  => [new PublicMediaLinkClaims('tenant-2', 'file-1', 'blob-1', 2000000000, 'photo.jpeg')],
            'file'    => [new PublicMediaLinkClaims('tenant-1', 'file-2', 'blob-1', 2000000000, 'photo.jpeg')],
            'blob'    => [new PublicMediaLinkClaims('tenant-1', 'file-1', 'blob-2', 2000000000, 'photo.jpeg')],
            'expires' => [new PublicMediaLinkClaims('tenant-1', 'file-1', 'blob-1', 2000000001, 'photo.jpeg')],
            'segment' => [new PublicMediaLinkClaims('tenant-1', 'file-1', 'blob-1', 2000000000, 'photo.png')],
        ];
    }

    public function test_a_signature_verifies_for_the_claims_it_was_made_for(): void
    {
        $signer    = new PublicMediaLinkSigner(self::KEY);
        $signature = $signer->sign($this->claims());

        $this->assertTrue($signer->verify($this->claims(), $signature));
    }

    public function test_the_signature_is_unpadded_base64url_of_a_sha256_mac(): void
    {
        $signature = (new PublicMediaLinkSigner(self::KEY))->sign($this->claims());

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $signature);
    }

    #[DataProvider('tamperedClaims')]
    public function test_a_changed_claim_does_not_verify(PublicMediaLinkClaims $tampered): void
    {
        $signer    = new PublicMediaLinkSigner(self::KEY);
        $signature = $signer->sign($this->claims());

        $this->assertFalse($signer->verify($tampered, $signature));
    }

    public function test_a_signature_of_another_key_does_not_verify(): void
    {
        $signature = (new PublicMediaLinkSigner(self::OTHER_KEY))->sign($this->claims());

        $this->assertFalse((new PublicMediaLinkSigner(self::KEY))->verify($this->claims(), $signature));
    }

    public function test_a_signature_made_with_a_previous_key_still_verifies_but_signing_uses_the_current_one(): void
    {
        $old       = new PublicMediaLinkSigner(self::OTHER_KEY);
        $rotated   = new PublicMediaLinkSigner(self::KEY, [self::OTHER_KEY]);
        $signature = $old->sign($this->claims());

        $this->assertTrue($rotated->verify($this->claims(), $signature));
        $this->assertSame((new PublicMediaLinkSigner(self::KEY))->sign($this->claims()), $rotated->sign($this->claims()));
        $this->assertFalse($old->verify($this->claims(), $rotated->sign($this->claims())));
    }

    public function test_malformed_signatures_do_not_verify(): void
    {
        $signer = new PublicMediaLinkSigner(self::KEY);

        foreach (['', 'abc', str_repeat('A', 43), str_repeat('A', 44), $signer->sign($this->claims()) . '='] as $malformed) {
            $this->assertFalse($signer->verify($this->claims(), $malformed));
        }
    }

    public function test_the_key_has_a_context_of_its_own(): void
    {
        $claims = $this->claims();
        $raw    = base64_decode(mb_substr(self::KEY, 7), true);

        $withoutContext = mb_rtrim(strtr(base64_encode(hash_hmac('sha256', $claims->message(), (string)$raw, true)), '+/', '-_'), '=');

        $this->assertNotSame($withoutContext, (new PublicMediaLinkSigner(self::KEY))->sign($claims));
    }

    public function test_a_malformed_previous_key_is_skipped_without_breaking_signing(): void
    {
        $signer = new PublicMediaLinkSigner(self::KEY, ['base64:***not-base64***', '', self::OTHER_KEY]);

        $this->assertTrue($signer->verify($this->claims(), (new PublicMediaLinkSigner(self::OTHER_KEY))->sign($this->claims())));
        $this->assertTrue($signer->verify($this->claims(), $signer->sign($this->claims())));
    }

    public function test_an_empty_key_refuses_to_sign_but_not_to_be_built(): void
    {
        // Built during boot (composer's package:discover runs before key:generate): building must not throw.
        $signer = new PublicMediaLinkSigner('');

        $this->expectException(RuntimeException::class);

        $signer->sign($this->claims());
    }

    private function claims(): PublicMediaLinkClaims
    {
        return new PublicMediaLinkClaims('tenant-1', 'file-1', 'blob-1', 2000000000, 'photo.jpeg');
    }
}
