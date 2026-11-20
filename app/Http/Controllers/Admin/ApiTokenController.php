<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Staff\Support\IssuedApiToken;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Admin\ApiTokenIssueRequest;
use App\Http\Requests\Admin\ApiTokenRotateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's API tokens in the admin panel (`adr-20261119-tenant-api-tokens`): issue, rotate and revoke, behind
 * the `ManageApiTokens` permission.
 *
 * The controller orchestrates: it authorizes, hands the work to {@see ApiTokenService} (which holds every rule and
 * writes the audit entry) and answers with a page or a redirect carrying a flash. The plain token exists only in the
 * issuing response and goes to the browser once, as the `apiToken` flash of the redirect; it is in no prop, row, log or
 * message.
 */
final class ApiTokenController extends Controller
{
    public function __construct(private readonly ApiTokenService $tokens)
    {
    }

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ManageApiTokens->value);

        $user = $this->actor($request);

        $table = new DataTable(
            sortable: ['name', 'expires_at', 'last_used_at', 'created_at'],
            searchable: ['name'],
            defaultSort: '-created_at',
        );

        return Inertia::render('Console/ApiTokens/Index', [
            'table' => $table->respond(
                $request,
                $this->tokens->query(),
                fn (ApiToken $token): array => $this->row($token),
            ),
            'grantable' => array_map(
                static fn (Permission $permission): array => [
                    'value'       => $permission->value,
                    'label'       => $permission->label(),
                    'description' => $permission->description(),
                    'sensitive'   => $permission->isSensitive(),
                ],
                $this->tokens->grantableBy($user),
            ),
            'lifetime' => [
                'default' => $this->tokens->defaultLifetimeDays(),
                'max'     => $this->tokens->maxLifetimeDays(),
            ],
            'can'  => ['manage' => true],
            'urls' => [
                'index' => $this->url('index'),
                'store' => $this->url('store'),
            ],
        ]);
    }

    public function store(ApiTokenIssueRequest $request): RedirectResponse
    {
        $issued = $this->tokens->issue(
            $this->actor($request),
            $request->input('name'),
            $request->input('abilities'),
            $request->input('expires_in_days'),
        );

        return $this->backToIndex($issued, trans('console.api_tokens.issued'));
    }

    public function rotate(ApiTokenRotateRequest $request, string $record): RedirectResponse
    {
        $issued = $this->tokens->rotate(
            $this->actor($request),
            $this->tokens->find($record),
            $request->input('abilities'),
            $request->input('expires_in_days'),
        );

        return $this->backToIndex($issued, trans('console.api_tokens.rotated'));
    }

    /**
     * Revokes the token; it goes back to the list the user was on, with its search, sort and page.
     */
    public function destroy(Request $request, string $record): RedirectResponse
    {
        Gate::authorize(Permission::ManageApiTokens->value);

        $user = $this->actor($request);

        $this->tokens->revoke($user, $this->tokens->find($record));

        Inertia::flash('success', trans('console.api_tokens.revoked'));

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * A row carries no secret: the last four characters of the secret are all of it that is shown.
     *
     * @return array<string, mixed>
     */
    private function row(ApiToken $token): array
    {
        $issuer = $token->issuer;

        return [
            'id'        => (string) $token->getKey(),
            'name'      => $token->name,
            'last_four' => $token->last_four,
            'abilities' => array_map(
                static fn (Permission $permission): array => [
                    'value'     => $permission->value,
                    'label'     => $permission->label(),
                    'sensitive' => $permission->isSensitive(),
                ],
                $token->permissions(),
            ),
            'issuer' => null === $issuer
                ? null
                : ['name' => $issuer->name, 'active' => $issuer->canAccessConsole()],
            'status'       => $this->status($token),
            'expires_at'   => $token->expires_at->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at'   => $token->created_at?->toIso8601String(),
            'rotateUrl'    => $this->url('rotate', ['record' => $token->getKey()]),
            'revokeUrl'    => $this->url('destroy', ['record' => $token->getKey()]),
        ];
    }

    private function status(ApiToken $token): string
    {
        if ($token->isRevoked()) {
            return 'revoked';
        }

        return $token->isExpired() ? 'expired' : 'active';
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        Gate::forUser($user)->authorize(Permission::ManageApiTokens->value);

        return $user;
    }

    /**
     * The plain token is flashed for the one request that follows and is not kept anywhere else.
     */
    private function backToIndex(IssuedApiToken $issued, string $message): RedirectResponse
    {
        Inertia::flash('success', $message);
        Inertia::flash('apiToken', ['name' => $issued->token->name, 'plain' => $issued->plain]);

        return redirect()->to($this->url('index'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        return route('console.admin.api-tokens.' . $action, $parameters, false);
    }
}
