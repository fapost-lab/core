<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\DTO\PublicMediaLinkClaims;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Services\PublicMediaLinkIssuer;
use App\Domains\Media\Services\PublicMediaLinkSigner;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * The public media link route, on two tenants in `host` mode: what a valid link serves and every way a link
 * can fail to open (all of them the same bare 404).
 */
final class PublicMediaLinkTest extends FeatureTestCase
{
    use RunsInHostMode;

    private const string TENANT_A = '00000000-0000-0000-0000-000000000001';

    private const string TENANT_B = '00000000-0000-0000-0000-000000000002';

    private const string BYTES = 'not-really-a-jpeg';

    private string $base;

    /** @var list<array{TenantInterface, string}> */
    private array $storedObjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string)config('tenancy.base_domain');

        $this->provisionSecondTenant();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedObjects as [$tenant, $path]) {
            $this->app->make(TenantMediaDisk::class)->resolve($tenant)->delete($path);
        }

        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
        $this->restoreTenancyResolution();
    }

    public function test_a_valid_link_serves_the_bytes_with_the_hardening_headers_and_no_cookie(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'Summer holiday.jpg');

        $response = $this->get($this->onHost('main', $link));

        $response->assertOk();
        $this->assertSame(self::BYTES, $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $this->assertSame('attachment; filename=summer-holiday.jpeg', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string)$response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Set-Cookie');
        $this->assertCount(0, $response->headers->getCookies());
    }

    public function test_head_returns_the_headers_without_a_body(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $response = $this->call('HEAD', $this->onHost('main', $link));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Disposition', 'attachment; filename=photo.jpeg');
    }

    public function test_a_link_of_one_tenant_does_not_open_on_the_host_of_another(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->get($this->onHost('second', $link))->assertNotFound();
        $this->get($this->onHost('main', $link))->assertOk();
    }

    public function test_the_id_of_another_tenants_file_does_not_open_under_a_link_of_the_first(): void
    {
        [$fileA, $linkA] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        [$fileB, $linkB] = $this->fileWithLink(self::TENANT_B, 'photo.jpg');

        $swapped = str_replace($fileA->id, $fileB->id, $linkA);

        $this->get($this->onHost('main', $swapped))->assertNotFound();
        $this->get($this->onHost('second', $swapped))->assertNotFound();
        // Its own link works on its own host, and only there.
        $this->get($this->onHost('second', $linkB))->assertOk();
        $this->get($this->onHost('main', $linkB))->assertNotFound();
    }

    public function test_an_expired_link_is_refused_and_the_refusal_is_logged_without_the_url(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 12:00:00');
        [$file, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        CarbonImmutable::setTestNow('2026-05-01 13:00:01');
        Log::spy();

        $this->get($this->onHost('main', $link))->assertNotFound();

        Log::shouldHaveReceived('warning')->once()->with('media.public_link.refused', Mockery::on(
            static fn (array $context): bool => $context === ['file_id' => $file->id, 'tenant_id' => self::TENANT_A, 'reason' => 'expired'],
        ));
    }

    public function test_a_link_valid_for_longer_than_the_ceiling_is_refused_even_when_signed(): void
    {
        [$file, , $blob] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        $expires         = CarbonImmutable::now()->getTimestamp() + 86400 + 120;
        $link            = $this->handCraftedLink($file, $blob, $expires, 'photo.jpeg');

        $this->get($this->onHost('main', $link))->assertNotFound();
    }

    public function test_a_handcrafted_link_within_the_ceiling_is_accepted(): void
    {
        [$file, , $blob] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        $link            = $this->handCraftedLink($file, $blob, CarbonImmutable::now()->getTimestamp() + 600, 'photo.jpeg');

        $this->get($this->onHost('main', $link))->assertOk();
    }

    public function test_a_soft_deleted_file_is_not_served(): void
    {
        [$file, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->inTenant(self::TENANT_A, static fn () => $file->delete());

        $this->get($this->onHost('main', $link))->assertNotFound();
    }

    public function test_a_tampered_segment_is_refused(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->get($this->onHost('main', preg_replace('/\.jpeg$/', '.png', $link)))->assertNotFound();
        $this->get($this->onHost('main', preg_replace('/photo\.jpeg$/', 'other.jpeg', $link)))->assertNotFound();
    }

    public function test_a_file_whose_blob_changed_is_no_longer_served_by_old_links(): void
    {
        [$file, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        $other         = $this->storeBlob(self::TENANT_A, 'image/jpeg');

        $this->inTenant(self::TENANT_A, static fn () => $file->forceFill(['blob_id' => $other->id])->save());

        $this->get($this->onHost('main', $link))->assertNotFound();
    }

    public function test_a_blob_missing_from_the_disk_is_refused(): void
    {
        [, $link, $blob] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->app->make(TenantMediaDisk::class)->resolve($this->tenant(self::TENANT_A))->delete($blob->storage_path);

        $this->get($this->onHost('main', $link))->assertNotFound();
    }

    public function test_a_malformed_signature_does_not_reach_the_controller(): void
    {
        [$file]  = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        $expires = CarbonImmutable::now()->getTimestamp() + 600;

        $this->get($this->onHost('main', "/media/public/{$file->id}/{$expires}/short/photo.jpeg"))->assertNotFound();
    }

    public function test_a_file_id_that_is_not_a_uuid_does_not_reach_the_controller(): void
    {
        [$file, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        $ulid          = (string) \Illuminate\Support\Str::ulid();

        $this->get($this->onHost('main', str_replace($file->id, mb_strtolower($ulid), $link)))->assertNotFound();
        $this->get($this->onHost('main', str_replace($file->id, mb_strtoupper($file->id), $link)))->assertNotFound();
    }

    public function test_a_stored_type_outside_the_allowlist_goes_out_as_opaque_bytes(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'page.html', 'text/html');

        $response = $this->get($this->onHost('main', $link));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_one_link_is_served_at_most_per_minute_times_a_minute(): void
    {
        config(['media.public_links.per_minute' => 2]);
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->get($this->onHost('main', $link))->assertOk();
        $this->get($this->onHost('main', $link))->assertOk();
        $this->get($this->onHost('main', $link))->assertTooManyRequests();

        // The budget is the link's own: another link to the same tenant still opens.
        [, $other] = $this->fileWithLink(self::TENANT_A, 'other.jpg');
        $this->get($this->onHost('main', $other))->assertOk();
    }

    public function test_a_link_whose_expiry_is_spelled_with_a_leading_zero_does_not_reach_the_controller(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->get($this->onHost('main', (string)preg_replace('#^(/media/public/[^/]+/)#', '${1}0', $link)))->assertNotFound();
    }

    public function test_a_stopped_tenant_still_serves_its_links(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());

        $this->get($this->onHost('main', $link))->assertOk();
    }

    public function test_the_base_domain_has_no_tenant_and_serves_nothing(): void
    {
        [, $link] = $this->fileWithLink(self::TENANT_A, 'photo.jpg');

        $this->get("http://{$this->base}" . $link)->assertNotFound();
    }

    /**
     * @return array{MediaFile, string, MediaBlob} the file, the link's path, the blob
     */
    private function fileWithLink(string $tenantId, string $name, string $mime = 'image/jpeg'): array
    {
        $blob = $this->storeBlob($tenantId, $mime);

        $file = $this->inTenant($tenantId, static fn (): MediaFile => MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'folder_id' => null,
            'name'      => $name,
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]));

        $tenant = $this->tenant($tenantId);
        $link   = $this->inTenant($tenantId, fn () => $this->app->make(PublicMediaLinkIssuer::class)->issue($file->load('blob'), $tenant, null));

        return [$file, (string)parse_url($link->url, PHP_URL_PATH), $blob];
    }

    private function storeBlob(string $tenantId, string $mime): MediaBlob
    {
        $tenant = $this->tenant($tenantId);
        $id     = mb_strtolower((string)\Illuminate\Support\Str::ulid());
        $path   = "tenants/{$tenantId}/image/2026-05/zz/{$id}.jpg";

        $this->app->make(TenantMediaDisk::class)->resolve($tenant)->put($path, self::BYTES);
        $this->storedObjects[] = [$tenant, $path];

        return $this->inTenant($tenantId, static fn (): MediaBlob => MediaBlob::query()->create([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'content_hash' => hash('sha256', $id),
            'storage_path' => $path,
            'storage_disk' => 'local',
            'size'         => mb_strlen(self::BYTES),
            'mime_type'    => $mime,
        ]));
    }

    private function handCraftedLink(MediaFile $file, MediaBlob $blob, int $expires, string $segment): string
    {
        $signature = $this->app->make(PublicMediaLinkSigner::class)->sign(new PublicMediaLinkClaims(
            tenantId: $file->tenant_id,
            fileId: $file->id,
            blobId: $blob->id,
            expires: $expires,
            segment: $segment,
        ));

        return "/media/public/{$file->id}/{$expires}/{$signature}/{$segment}";
    }

    private function onHost(string $slug, string $path): string
    {
        return "http://{$slug}.{$this->base}{$path}";
    }

    private function tenant(string $tenantId): TenantInterface
    {
        return $this->app->make(TenantRepositoryInterface::class)->getById($tenantId);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private function inTenant(string $tenantId, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant($tenantId), $callback(...));
    }
}
