<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Flow\Services\TranslationOverrideEditor;
use App\Domains\Flow\Translations\TranslationScope;
use App\Http\DataTable\ArrayDataTable;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The translations page both panels render (`Console/Translations/Index`): the catalog keys of one override layer
 * as a list, filtered by group, searched by key and description, sorted by group or key, 25/50/100 a page, as the
 * Filament table was. Only the shape of the answer lives here; each action authorizes on its own.
 */
trait RendersTranslations
{
    /**
     * @param  Closure(string, string): string  $rowUrl  the URL of a key's `update` or `reset` write
     */
    private function renderTranslations(
        Request $request,
        TranslationOverrideEditor $editor,
        TranslationScope $scope,
        string $indexUrl,
        Closure $rowUrl,
    ): Response {
        $groups = $editor->groups();
        $table  = new ArrayDataTable(
            sortable: ['group', 'key'],
            searchable: ['key', 'description'],
            defaultSort: 'key',
            filters: ['group' => $groups],
        );

        return Inertia::render('Console/Translations/Index', [
            'table' => $table->respond(
                $request,
                $editor->rows($scope, app()->getLocale()),
                static fn (array $row): array => [
                    ...$row,
                    'updateUrl' => $rowUrl('update', $row['key']),
                    'resetUrl'  => $rowUrl('reset', $row['key']),
                ],
            ),
            'languages' => $editor->languages(),
            'groups'    => $groups,
            // An assistant's page also shows the tenant's overrides, as inherited.
            'layered' => $scope->isAssistant(),
            'urls'    => ['index' => $indexUrl],
        ]);
    }
}
