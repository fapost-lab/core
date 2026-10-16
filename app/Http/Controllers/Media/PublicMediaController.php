<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domains\Media\Services\PublicMediaLinkResolver;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a media file to the provider that was handed its public link (routes/media-public.php).
 *
 * No session, no user: the signed path is the whole authorization. Every refusal is the same bare 404. The file goes
 * out as an attachment under a sandboxing policy, so a type the console would never render cannot run in the
 * tenant's origin.
 */
final class PublicMediaController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly PublicMediaLinkResolver $resolver,
        private readonly TenantMediaDisk $disks,
    ) {
    }

    public function __invoke(string $file, string $expires, string $signature, string $segment): Response
    {
        $tenant = $this->tenantContext->get();
        $blob   = $this->resolver->resolve($tenant, $file, (int)$expires, $signature, $segment);

        if (null === $blob) {
            abort(404);
        }

        return $this->disks->resolve($tenant)->response(
            $blob->storage_path,
            $segment,
            [
                'Content-Type'            => $this->servedType($blob->mime_type),
                'X-Content-Type-Options'  => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'Referrer-Policy'         => 'no-referrer',
                'X-Robots-Tag'            => 'noindex',
                'Cache-Control'           => 'private, no-store',
            ],
            'attachment',
        );
    }

    /**
     * The stored type when the upload allowlist (`media.allowed_mime_types`) holds it, otherwise opaque bytes: a blob
     * that predates the allowlist or came in by another path never goes out under a type a browser would render.
     */
    private function servedType(string $mimeType): string
    {
        $allowed = config('media.allowed_mime_types', []);

        return is_array($allowed) && in_array(mb_strtolower($mimeType), $allowed, true)
            ? $mimeType
            : 'application/octet-stream';
    }
}
