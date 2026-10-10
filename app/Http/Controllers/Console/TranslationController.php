<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Services\TranslationOverrideEditor;
use App\Domains\Flow\Translations\TranslationScope;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Concerns\RendersTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Console\TranslationOverridesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The assistant's translations on the Inertia console: its own overrides of the system catalog, with the tenant's
 * overrides shown as inherited. Writes touch only `assistant_translations`. The permission is the one the Filament page
 * asked for; the assistant in the URL is guarded by the console stack (one the user may not view is a 404).
 */
final class TranslationController extends Controller
{
    use RendersTranslations;

    public function __construct(
        private readonly TranslationOverrideEditor $editor,
        private readonly CurrentAssistantInterface $assistant,
        private readonly TenantContextInterface $tenant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageTranslations->value);

        return $this->renderTranslations(
            $request,
            $this->editor,
            $this->scope(),
            $this->url('index'),
            fn (string $action, string $key): string => $this->url($action, ['key' => $key]),
        );
    }

    /*
     * Route parameters reach an action by position, so `$tenant` (the assistant in the URL) comes before `$key`.
     */
    public function update(TranslationOverridesRequest $request, string $tenant, string $key): RedirectResponse
    {
        $this->editor->save($this->scope(), $this->knownKey($key), $request->values());

        Inertia::flash('success', trans('console.translations.saved'));

        return redirect()->back(fallback: $this->url('index'));
    }

    public function reset(string $tenant, string $key): RedirectResponse
    {
        Gate::authorize(Permission::ManageTranslations->value);

        $this->editor->reset($this->scope(), $this->knownKey($key));

        Inertia::flash('success', trans('console.translations.reset_done'));

        return redirect()->back(fallback: $this->url('index'));
    }

    private function scope(): TranslationScope
    {
        return TranslationScope::assistant($this->tenant->get()->getId(), (string) $this->assistant->get()->getKey());
    }

    /**
     * Only keys of the catalog have overrides; anything else is a 404.
     */
    private function knownKey(string $key): string
    {
        abort_if(null === $this->editor->entry($key), 404);

        return $key;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = 'index' === $action ? 'filament.assistant.pages.translations' : 'console.translations.' . $action;

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
