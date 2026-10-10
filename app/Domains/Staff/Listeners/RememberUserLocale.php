<?php

declare(strict_types=1);

namespace App\Domains\Staff\Listeners;

use App\Domains\Staff\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Translation\Translator;

/**
 * Keeps the language a staff user signs in with, so a worker can write to them in it later.
 *
 * Only fills an empty choice: a language picked in the console is never overwritten by whatever the
 * browser sent at the next sign-in. Accounts of other guards (an operator package's own) are ignored.
 */
final readonly class RememberUserLocale
{
    /** @var list<string> */
    private const array SUPPORTED = ['en', 'ru', 'uk'];

    public function __construct(private Translator $translator)
    {
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || null !== $user->locale) {
            return;
        }

        $locale = $this->translator->getLocale();

        if (! in_array($locale, self::SUPPORTED, true)) {
            return;
        }

        $user->forceFill(['locale' => $locale])->saveQuietly();
    }
}
