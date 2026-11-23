<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Credentials\CredentialWriter;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\OAuth2\OAuth2Consent;
use App\Domains\Credentials\OAuth2\OAuth2ConsentFailed;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting an `oauth2_authorization_code` credential by consent, from its console page: `connect` sends the browser
 * to the provider, the provider sends it back to `callback` on the tenant's own host, and `disconnect` deletes the
 * grant. Every action needs `ManageCredentials` (`CredentialPolicy::update`); the work is {@see OAuth2Consent}'s.
 *
 * The callback is a GET, so the stopped-tenant middleware lets it through: it is refused here, since it writes the
 * grant. It answers with a redirect and a flash chosen from a fixed list; the query it got (code, state, the
 * provider's error description) is passed on to the consent and written nowhere — not even into the session's
 * previous URL.
 */
final class CredentialOAuthController extends Controller
{
    public function __construct(
        private readonly CredentialWriter $credentials,
        private readonly OAuth2Consent $consent,
        private readonly RefuseWritesWhenTenantStopped $writes,
    ) {
    }

    /**
     * The callback URL of the tenant, to register at the provider: one per tenant, on the host of this request.
     */
    public static function callbackUrl(): string
    {
        return route('console.admin.credentials.oauth.callback');
    }

    public function connect(Request $request, string $record): Response
    {
        $credential = $this->connectable($record);

        Gate::authorize('update', $credential);

        return Inertia::location($this->consent->start($credential, (string) $request->user()?->getAuthIdentifier(), $request->session(), self::callbackUrl()));
    }

    public function callback(Request $request): RedirectResponse
    {
        Gate::authorize('update', new Credential());

        $query = $request->query();

        // The session remembers the URL of every GET as the "previous" one, query included: the code and the state
        // would stay in it. The query is taken here and dropped from the request before the session sees it.
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');

        if ($this->writes->refusesWritesFor($request->user())) {
            return $this->back(null, 'error', trans('console.credentials.oauth.flash.tenant_stopped'));
        }

        try {
            $credential = $this->consent->complete($query, (string) $request->user()?->getAuthIdentifier(), $request->session());
        } catch (OAuth2ConsentFailed $failure) {
            return $this->back($failure->credentialId, 'error', trans("console.credentials.oauth.flash.{$failure->reason}", ['code' => $failure->providerCode ?? '']));
        }

        return $this->back((string) $credential->getKey(), 'success', trans('console.credentials.oauth.flash.connected'));
    }

    public function disconnect(string $record): RedirectResponse
    {
        $credential = $this->connectable($record);

        Gate::authorize('update', $credential);

        $this->consent->disconnect($credential);

        return $this->back((string) $credential->getKey(), 'success', trans('console.credentials.oauth.flash.disconnected'));
    }

    private function connectable(string $record): Credential
    {
        $credential = $this->credentials->find($record);

        abort_unless(OAuth2AuthorizationCodeType::ID === $credential->type, Response::HTTP_NOT_FOUND);

        return $credential;
    }

    /**
     * To the credential's page, or to the list when there is no credential to go back to.
     */
    private function back(?string $credentialId, string $kind, string $message): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->to(null === $credentialId
            ? route('console.admin.credentials.index', [], false)
            : route('console.admin.credentials.edit', ['record' => $credentialId], false));
    }
}
