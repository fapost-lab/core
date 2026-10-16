<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Media\Providers\MediaServiceProvider;
use App\Domains\Media\Services\PublicMediaLinkIssuer;
use App\Domains\Media\Services\PublicMediaLinkResolver;
use App\Domains\Media\Services\PublicMediaLinkSigner;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * A public media link is a bearer credential. Whoever holds the signer can mint one for any file of any tenant, so
 * the class is reachable from exactly two places: the issuer, which signs, and the resolver, which verifies. The
 * service provider only binds it. That the resolver never signs is held by
 * {@see \Tests\Unit\Architecture\PublicMediaLinkSigningTest}: PHPat sees a dependency, not which method is called.
 */
final class PublicMediaLinkSignerTest
{
    public function test_only_the_issuer_and_the_resolver_depend_on_the_link_signer(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App'),
                    Selector::NoneOf(
                        Selector::classname(PublicMediaLinkSigner::class),
                        Selector::classname(PublicMediaLinkIssuer::class),
                        Selector::classname(PublicMediaLinkResolver::class),
                        Selector::classname(MediaServiceProvider::class),
                    ),
                ),
            )
            ->shouldNotDependOn()
            ->classes(Selector::classname(PublicMediaLinkSigner::class))
            ->because('Public media links are issued only by PublicMediaLinkIssuer and checked only by PublicMediaLinkResolver.');
    }
}
