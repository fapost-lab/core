<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditActor;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Staff\Enums\OperatorRevokeOutcome;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\ApiTokenSettings;
use App\Domains\Staff\Support\IssuedApiToken;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Issues, rotates and revokes the current tenant's API tokens (`adr-20261119-tenant-api-tokens`), and is the only code
 * that writes them.
 *
 * - No escalation (§7): every ability is a `Permission` value the actor holds, never one a token may not hold, and for
 *   an actor who is not an administrator never one a record-level policy narrows for them.
 * - Rotation never widens (§7, §9): the successor's abilities are a subset of the predecessor's and pass the same rules
 *   against the rotator; the predecessor's expiry becomes the earlier of its own and now plus the grace. An expired or
 *   revoked token is not rotated. The predecessor is marked notified, so its shortened life sends no expiry notice.
 * - Every token has an expiry, at most the platform's maximum (§9).
 * - The platform support user does nothing here (§8), whatever the Gate said.
 * - The installation operator only revokes, and only with the leaked token itself (§8).
 *
 * Each write and its audit entry commit together; the entry names the token and its last four characters, never the
 * secret or its hash. A refusal is a {@see ValidationException} (or {@see AuthorizationException} for the support user).
 */
final readonly class ApiTokenService
{
    public const int MAX_NAME_LENGTH = 120;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private Gate $gate,
        private AuditTrailInterface $audit,
        private PlatformSettingsReaderInterface $settings,
    ) {
    }

    /**
     * The tenant's tokens, newest first, for a list to filter, sort and page.
     *
     * @return Builder<ApiToken>
     */
    public function query(): Builder
    {
        return ApiToken::query()->with('issuer');
    }

    /**
     * @throws ModelNotFoundException when the id is not a token of this tenant
     */
    public function find(string $id): ApiToken
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(ApiToken::class, [$id]);
        }

        return ApiToken::query()->findOrFail(mb_strtolower($id));
    }

    /**
     * The longest lifetime an issuer may choose, in days.
     */
    public function maxLifetimeDays(): int
    {
        return max(1, min(ApiTokenSettings::CEILING_DAYS, $this->settings->integer(ApiTokenSettings::MAX_TTL_DAYS)));
    }

    /**
     * The lifetime a new token gets when its issuer does not choose one, in days.
     */
    public function defaultLifetimeDays(): int
    {
        return max(1, min($this->maxLifetimeDays(), $this->settings->integer(ApiTokenSettings::DEFAULT_TTL_DAYS)));
    }

    /**
     * The permissions this actor may put on a token, in the enum's order.
     *
     * @return list<Permission>
     */
    public function grantableBy(User $actor): array
    {
        if ($actor->isPlatformSupport()) {
            return [];
        }

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => $this->mayGrant($actor, $permission),
        ));
    }

    /**
     * @param  mixed  $abilities  the requested `Permission` values
     * @param  mixed  $lifetimeDays  days until expiry; null for the default
     *
     * @throws ValidationException
     * @throws AuthorizationException when the actor is the platform support user
     */
    public function issue(User $actor, mixed $name, mixed $abilities, mixed $lifetimeDays = null): IssuedApiToken
    {
        $this->refuseSupport($actor);

        $errors      = [];
        $validName   = $this->validName($name, $errors);
        $permissions = $this->validAbilities($actor, $abilities, $errors);
        $days        = $this->validLifetime($lifetimeDays, $errors);

        $this->failOn($errors);

        return DB::transaction(function () use ($actor, $validName, $permissions, $days): IssuedApiToken {
            $issued = $this->create($actor, $validName, $permissions, Carbon::now()->addDays($days));

            $this->audit->record(new AuditRecord(
                action: AuditAction::ApiTokenIssued,
                subject: $issued->token,
                subjectLabel: $issued->token->name,
                changes: AuditChanges::created($this->auditValues($issued->token)),
            ));

            return $issued;
        });
    }

    /**
     * Issues a successor with the same name and the given abilities (by default the predecessor's) and shortens the
     * predecessor's life to the grace window.
     *
     * @param  mixed  $abilities  the successor's `Permission` values; null keeps the predecessor's
     * @param  mixed  $lifetimeDays  days until the successor's expiry; null for the default
     *
     * @throws ValidationException
     * @throws AuthorizationException when the actor is the platform support user
     */
    public function rotate(User $actor, ApiToken $token, mixed $abilities = null, mixed $lifetimeDays = null): IssuedApiToken
    {
        $this->refuseSupport($actor);

        return DB::transaction(function () use ($actor, $token, $abilities, $lifetimeDays): IssuedApiToken {
            /** @var ApiToken $predecessor */
            $predecessor = ApiToken::query()->whereKey($token->getKey())->lockForUpdate()->firstOrFail();

            if (! $predecessor->isUsable()) {
                throw ValidationException::withMessages(['token' => __('api_token_validation.not_rotatable')]);
            }

            $errors      = [];
            $requested   = $abilities ?? $predecessor->abilities;
            $permissions = $this->validAbilities($actor, $requested, $errors);
            $days        = $this->validLifetime($lifetimeDays, $errors);

            if ([] === ($errors['abilities'] ?? []) && [] !== $this->outside($permissions, $predecessor->permissions())) {
                $errors['abilities'][] = __('api_token_validation.widens');
            }

            $this->failOn($errors);

            $now   = Carbon::now();
            $until = $now->copy()->addHours(max(0, min(ApiTokenSettings::MAX_GRACE_HOURS, $this->settings->integer(ApiTokenSettings::ROTATION_GRACE_HOURS))));

            $predecessor->forceFill([
                'expires_at'         => $predecessor->expires_at->lessThan($until) ? $predecessor->expires_at : $until,
                'expiry_notified_at' => $predecessor->expiry_notified_at ?? $now,
            ])->save();

            $successor = $this->create($actor, $predecessor->name, $permissions, $now->copy()->addDays($days));

            $this->audit->record(new AuditRecord(
                action: AuditAction::ApiTokenRotated,
                subject: $successor->token,
                subjectLabel: $successor->token->name,
                changes: AuditChanges::created($this->auditValues($successor->token)),
                properties: [
                    'replaced_token_id'         => (string) $predecessor->getKey(),
                    'replaced_last_four'        => $predecessor->last_four,
                    'replaced_token_expires_at' => $predecessor->expires_at->toIso8601String(),
                ],
            ));

            return $successor;
        });
    }

    /**
     * Revokes the token now; it stops working on the next request. An already revoked token is left as it is.
     *
     * @throws AuthorizationException when the actor is the platform support user
     */
    public function revoke(User $actor, ApiToken $token): void
    {
        $this->refuseSupport($actor);

        DB::transaction(function () use ($actor, $token): void {
            $revoked = ApiToken::query()
                ->whereKey($token->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now(), 'revoked_by' => $actor->getKey()]);

            if (0 === $revoked) {
                return;
            }

            $this->audit->record(new AuditRecord(
                action: AuditAction::ApiTokenRevoked,
                subject: $token,
                subjectLabel: $token->name,
                properties: ['last_four' => $token->last_four],
            ));
        });

        $token->refresh();
    }

    /**
     * The installation operator's incident revoke (§8): given the leaked token itself, revoke it if it is a token of
     * this tenant and its secret matches. Recorded as a `support` actor named after the operator; `revoked_by` stays
     * empty, since no staff user acted. A wrong value says nothing about which part was wrong.
     */
    public function revokeByOperator(#[SensitiveParameter] string $plain, string $operatorName): OperatorRevokeOutcome
    {
        if (1 !== preg_match(ApiToken::PATTERN, mb_trim($plain), $parts)) {
            return OperatorRevokeOutcome::NotFound;
        }

        return DB::transaction(function () use ($parts, $operatorName): OperatorRevokeOutcome {
            $token = ApiToken::query()->whereKey(ApiToken::rowIdOf($parts[1]))->lockForUpdate()->first();

            if (! $token instanceof ApiToken || ! hash_equals($token->token_hash, ApiToken::hashSecret($parts[2]))) {
                return OperatorRevokeOutcome::NotFound;
            }

            if ($token->isRevoked()) {
                return OperatorRevokeOutcome::AlreadyRevoked;
            }

            $token->forceFill(['revoked_at' => Carbon::now(), 'revoked_by' => null])->save();

            $this->audit->record(new AuditRecord(
                action: AuditAction::ApiTokenRevoked,
                subject: $token,
                subjectLabel: $token->name,
                properties: ['last_four' => $token->last_four],
                actor: new AuditActor(AuditActorKind::Support, null, $operatorName, ['operator_name' => $operatorName]),
            ));

            return OperatorRevokeOutcome::Revoked;
        });
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function create(User $actor, string $name, array $permissions, Carbon $expiresAt): IssuedApiToken
    {
        $secret = $this->newSecret();

        $token = ApiToken::query()->create([
            'name'       => $name,
            'token_hash' => ApiToken::hashSecret($secret),
            'last_four'  => mb_substr($secret, -4),
            'abilities'  => array_map(static fn (Permission $permission): string => $permission->value, $permissions),
            'created_by' => $actor->getKey(),
            'expires_at' => $expiresAt,
        ]);

        return new IssuedApiToken($token, ApiToken::PREFIX . ApiToken::publicIdOf((string) $token->getKey()) . '_' . $secret);
    }

    /**
     * 40 characters of base62 from the CSPRNG, about 238 bits.
     */
    private function newSecret(): string
    {
        $secret = '';
        $last   = mb_strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < ApiToken::SECRET_LENGTH; ++$i) {
            $secret .= self::ALPHABET[random_int(0, $last)];
        }

        return $secret;
    }

    private function mayGrant(User $actor, Permission $permission): bool
    {
        if ($permission->isForbiddenToTokens()) {
            return false;
        }

        if (! $actor->isAdmin() && $permission->isNarrowedByRecordPolicy()) {
            return false;
        }

        return $this->gate->forUser($actor)->allows($permission->value);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validName(mixed $name, array &$errors): string
    {
        $name = is_string($name) ? mb_trim($name) : '';

        if ('' === $name) {
            $errors['name'][] = __('api_token_validation.name_required');
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'][] = __('api_token_validation.name_too_long', ['max' => self::MAX_NAME_LENGTH]);
        }

        return $name;
    }

    /**
     * @param  array<string, list<string>>  $errors
     *
     * @return list<Permission>
     */
    private function validAbilities(User $actor, mixed $abilities, array &$errors): array
    {
        if (! is_array($abilities) || [] === $abilities) {
            $errors['abilities'][] = __('api_token_validation.abilities_required');

            return [];
        }

        $permissions = [];

        foreach ($abilities as $ability) {
            $permission = is_string($ability) ? Permission::tryFrom($ability) : null;

            if (null === $permission) {
                $errors['abilities'][] = __('api_token_validation.ability_unknown');

                continue;
            }

            if (! $this->mayGrant($actor, $permission)) {
                $errors['abilities'][] = __('api_token_validation.ability_not_grantable', ['permission' => $permission->label()]);

                continue;
            }

            $permissions[$permission->value] = $permission;
        }

        return array_values($permissions);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validLifetime(mixed $days, array &$errors): int
    {
        if (null === $days || '' === $days) {
            return $this->defaultLifetimeDays();
        }

        $max   = $this->maxLifetimeDays();
        $value = filter_var($days, FILTER_VALIDATE_INT);

        if (false === $value || $value < 1 || $value > $max) {
            $errors['expires_in_days'][] = __('api_token_validation.lifetime', ['max' => $max]);

            return $this->defaultLifetimeDays();
        }

        return $value;
    }

    /**
     * The permissions of the first list that are not in the second.
     *
     * @param  list<Permission>  $permissions
     * @param  list<Permission>  $allowed
     *
     * @return list<Permission>
     */
    private function outside(array $permissions, array $allowed): array
    {
        return array_values(array_filter($permissions, static fn (Permission $permission): bool => ! in_array($permission, $allowed, true)));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(ApiToken $token): array
    {
        return [
            'name'       => $token->name,
            'last_four'  => $token->last_four,
            'abilities'  => $token->abilities,
            'expires_at' => $token->expires_at->toIso8601String(),
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function refuseSupport(User $actor): void
    {
        if ($actor->isPlatformSupport()) {
            throw new AuthorizationException();
        }
    }

    /**
     * @param  array<string, list<string>>  $errors
     *
     * @throws ValidationException
     */
    private function failOn(array $errors): void
    {
        if ([] !== $errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
