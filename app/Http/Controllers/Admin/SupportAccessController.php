<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Services\SupportAccessEntryService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's support access log in the admin panel: which platform operator entered as the platform support user,
 * from which IP, when they entered and left. Read only — Core writes the entries (`SupportAccessEntryService`).
 *
 * The screen never existed in Filament (new Filament screens are frozen), so its route has a `console.admin.*` name.
 */
final class SupportAccessController extends Controller
{
    public function __construct(
        private readonly SupportAccessEntryService $entries,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupportAccessEntry::class);

        $table = new DataTable(
            sortable: ['entered_at', 'left_at', 'operator_name'],
            searchable: ['operator_name', 'operator_email', 'ip'],
            defaultSort: '-entered_at',
        );

        return Inertia::render('Console/SupportAccess/Index', [
            'table' => $table->respond(
                $request,
                $this->entries->query(),
                fn (SupportAccessEntry $entry): array => [
                    'id'            => (string) $entry->getKey(),
                    'operatorName'  => $entry->operator_name,
                    'operatorEmail' => $entry->operator_email,
                    'ip'            => $entry->ip,
                    'enteredAt'     => $entry->entered_at->toIso8601String(),
                    'leftAt'        => $entry->left_at?->toIso8601String(),
                    'isOpen'        => $this->entries->isOpen($entry),
                ],
            ),
            'urls' => [
                'index' => route('console.admin.support-access.index', [], false),
            ],
        ]);
    }
}
