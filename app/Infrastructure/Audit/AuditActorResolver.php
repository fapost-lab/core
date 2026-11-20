<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domains\Audit\Contracts\AuditActorResolverInterface;
use App\Domains\Audit\DTOs\AuditActor;
use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\ApiTokenGuard;
use App\Domains\Staff\Support\SupportAccessSession;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * Who is acting: a tenant API token the request authenticated with (`api_token`, labelled `API · <name>`), else from the
 * staff guard a signed-in staff user, the platform support user (with the operator the support session names), or the
 * system when nobody is signed in — a queued job or a command, which have no user.
 *
 * The token guard is only asked whether it already holds a token (`hasUser()`): asking it for the user would run the
 * authenticator on every console write, count a failure against the client and log it.
 *
 * Lives outside the Audit domain because it knows Staff. Bound `scoped`; it reads the guard and the request at call time,
 * so nothing of one job or request is kept for the next.
 */
final readonly class AuditActorResolver implements AuditActorResolverInterface
{
    public const string STAFF_GUARD = 'web';

    public const string API_TOKEN_GUARD = ApiTokenGuard::NAME;

    public function __construct(
        private AuthFactory $auth,
    ) {
    }

    /**
     * A staff user acting for themselves, named as they were at the time.
     */
    public static function staff(User $user): AuditActor
    {
        return new AuditActor(AuditActorKind::Staff, $user, $user->name);
    }

    public function current(): AuditActor
    {
        $tokenGuard = $this->auth->guard(self::API_TOKEN_GUARD);

        if ($tokenGuard->hasUser()) {
            $token = $tokenGuard->user();

            if ($token instanceof ApiToken) {
                return new AuditActor(AuditActorKind::ApiToken, $token, $token->auditLabel());
            }
        }

        $user = $this->auth->guard(self::STAFF_GUARD)->user();

        if (! $user instanceof User) {
            return AuditActor::system();
        }

        return $user->isPlatformSupport() ? $this->support($user) : self::staff($user);
    }

    /**
     * The platform support user is one per tenant and shared by every operator; the support session says which operator
     * it is and which entry of the support access log the action belongs to. The label stored is the operator's name, or
     * nothing without a support session: the words around it come from the language files when the entry is shown.
     */
    private function support(User $user): AuditActor
    {
        // The current request, read now: a scoped resolver may outlive the request it was built in (tests send several).
        $request = request();
        $current = $request instanceof Request && $request->hasSession()
            ? (new SupportAccessSession($request->session()))->current()
            : null;

        if (null === $current) {
            return new AuditActor(AuditActorKind::Support, $user, null);
        }

        return new AuditActor(
            AuditActorKind::Support,
            $user,
            $current['operator_name'],
            [
                'support_entry_id' => $current['entry_id'],
                'operator_name'    => $current['operator_name'],
            ],
        );
    }
}
