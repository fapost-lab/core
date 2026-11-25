<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Credentials\Contracts\ValidatesOwnSettingsInterface;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\OAuth2\OAuth2GrantRepository;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\Enums\CredentialFieldKind;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes the {@see Credential}s of the current tenant, and is the only code that does.
 *
 * It checks what the credential's type declares: the required fields, the choices of a select, an https URL for a
 * URL field whose host is one of the allowed hosts (the secret is only sent where the admin said), a secret of at
 * least {@see self::MIN_SECRET_LENGTH} characters, and 1 to {@see self::MAX_HOSTS} allowed host patterns. A field
 * the type does not declare is dropped. On an update a secret left empty keeps the stored one, and the type never
 * changes. A refusal is a {@see ValidationException} whose messages never carry a value.
 *
 * The secret is write-only, so an edit may not send a stored one somewhere new: when the allowed hosts gain a host the
 * old list did not cover, or the token URL moves to another host, every secret field must be entered again, and the
 * consent grant of an `oauth2_authorization_code` credential is dropped. Narrowing the list needs neither. A
 * credential whose type is no longer registered cannot be updated.
 *
 * A type may add rules of its own for a setting ({@see ValidatesOwnSettingsInterface}).
 *
 * Each write and its audit entry commit together. The entry names the credential's name, type and hosts, which
 * settings changed (`settings.<key>`), whether the secret was replaced and whether the hosts were widened; no setting
 * or secret value is recorded. After an update or a
 * delete has committed, the cached OAuth2 token of the credential is dropped. The consent grant of an
 * `oauth2_authorization_code` credential is deleted in the same transaction when the client it was given to changes
 * (a setting of {@see OAuth2AuthorizationCodeType::GRANT_BOUND_SETTINGS} or the secret), and with the credential.
 */
final readonly class CredentialWriter
{
    public const int MIN_SECRET_LENGTH = 8;

    public const int MAX_HOSTS = 20;

    public const int MAX_NAME_LENGTH = 120;

    public const int MAX_SETTING_LENGTH = 255;

    /** The setting of the OAuth2 types that names where the client secret is sent. */
    private const string TOKEN_URL_SETTING = 'token_url';

    private const string SETTINGS_MARKER = 'settings.';

    public function __construct(
        private TenantContextInterface $tenants,
        private CredentialTypeRegistry $types,
        private OAuth2TokenCache $tokens,
        private OAuth2GrantRepository $grants,
        private AuditTrailInterface $audit,
    ) {
    }

    /**
     * The credentials of the current tenant, for a list to filter, sort and page.
     *
     * @return Builder<Credential>
     */
    public function query(): Builder
    {
        return Credential::query()->where('tenant_id', $this->tenantId());
    }

    /**
     * @throws ModelNotFoundException when the id is not a credential of the current tenant
     */
    public function find(string $id): Credential
    {
        if (!Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Credential::class, [$id]);
        }

        return $this->query()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  array{name?: mixed, type?: mixed, settings?: mixed, secrets?: mixed, allowed_hosts?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Credential
    {
        $typeId = $data['type'] ?? null;

        if (!is_string($typeId) || !$this->types->has($typeId)) {
            throw ValidationException::withMessages(['type' => __('credential_validation.type_unknown')]);
        }

        $type   = $this->types->get($typeId);
        $fields = $type->fields();
        $errors = [];

        $name     = $this->validName($data['name'] ?? null, null, $errors);
        $hosts    = $this->validHosts($data['allowed_hosts'] ?? null, $errors);
        $settings = $this->validSettings($type, $fields, $data['settings'] ?? null, $hosts, $errors);
        $secrets  = $this->validSecrets($fields, $data['secrets'] ?? null, [], $errors);

        $this->failOn($errors);

        try {
            $credential = DB::transaction(function () use ($name, $typeId, $settings, $hosts, $secrets): Credential {
                $credential = Credential::query()->create([
                    'tenant_id'         => $this->tenantId(),
                    'name'              => $name,
                    'type'              => $typeId,
                    'settings'          => $settings,
                    'allowed_hosts'     => $hosts,
                    'secret'            => $secrets,
                    'secret_changed_at' => Carbon::now(),
                ]);

                $this->record(AuditAction::CredentialCreated, $credential, AuditChanges::created([
                    'name'          => $credential->name,
                    'type'          => $credential->type,
                    'allowed_hosts' => $credential->allowed_hosts,
                ]));

                return $credential;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('credential_validation.name_taken')]);
        }

        return $credential;
    }

    /**
     * @param  array{name?: mixed, settings?: mixed, secrets?: mixed, allowed_hosts?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function update(Credential $credential, array $data): Credential
    {
        if (!$this->types->has($credential->type)) {
            // Its fields are unknown, so whatever was saved would be read as nothing and overwritten.
            throw ValidationException::withMessages(['type' => __('credential_validation.type_unknown')]);
        }

        $type   = $this->types->get($credential->type);
        $fields = $type->fields();
        $errors = [];

        $name     = $this->validName($data['name'] ?? null, $credential, $errors);
        $hosts    = $this->validHosts($data['allowed_hosts'] ?? null, $errors);
        $settings = $this->validSettings($type, $fields, $data['settings'] ?? null, $hosts, $errors);
        $stored   = $this->storedSecrets($credential);

        $hostsWidened   = self::hostsWidened($credential->allowed_hosts, $hosts);
        $tokenHostMoved = self::tokenHostMoved($credential, $settings);

        $secrets = $this->validSecrets($fields, $data['secrets'] ?? null, $stored, $errors, $hostsWidened || $tokenHostMoved);

        $this->failOn($errors);

        $secretReplaced = self::sorted($secrets) !== self::sorted($stored);
        // A grant's tokens are a person's, not the admin's to re-enter: a host they could newly reach drops them too.
        $clientChanged = $secretReplaced || $hostsWidened || self::grantBoundSettingsChanged($credential, $settings);

        try {
            DB::transaction(function () use ($credential, $name, $settings, $hosts, $secrets, $secretReplaced, $clientChanged, $hostsWidened): void {
                $before = ['name' => $credential->name, 'allowed_hosts' => $credential->allowed_hosts, ...self::settingsFlat($credential->settings)];

                $credential->fill(['name' => $name, 'settings' => $settings, 'allowed_hosts' => $hosts]);

                if ($secretReplaced) {
                    $credential->fill(['secret' => $secrets, 'secret_changed_at' => Carbon::now()]);
                }

                $credential->save();

                $after   = ['name' => $credential->name, 'allowed_hosts' => $credential->allowed_hosts, ...self::settingsFlat($credential->settings)];
                $markers = array_values(array_unique(array_filter(
                    [...array_keys($before), ...array_keys($after)],
                    static fn (string $key): bool => str_starts_with($key, self::SETTINGS_MARKER),
                )));
                sort($markers);

                $changes = AuditChanges::between($before, $after, ['name', 'allowed_hosts'], $markers);

                $properties = ['secret_replaced' => $secretReplaced];

                if ($hostsWidened) {
                    $properties['hosts_widened'] = true;
                }

                if ($clientChanged && $this->grants->delete((string) $credential->getKey())) {
                    $properties['grant_dropped'] = true;
                }

                if (!$changes->isEmpty() || $secretReplaced) {
                    $this->record(AuditAction::CredentialUpdated, $credential, $changes, $properties);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('credential_validation.name_taken')]);
        }

        $this->tokens->forget($this->tenantId(), (string) $credential->getKey());

        return $credential;
    }

    /**
     * One model at a time, so its own events run.
     */
    public function delete(Credential $credential): void
    {
        DB::transaction(function () use ($credential): void {
            // The credential's row first, then its grant: the order a consent takes them in, so a reconnect and a
            // delete at once wait for each other instead of deadlocking.
            $this->grants->lockedCredential((string) $credential->getKey());

            // The foreign key cascades too; deleted here so it does not depend on the connection enforcing it.
            $this->grants->delete((string) $credential->getKey());
            $credential->delete();

            $this->record(AuditAction::CredentialDeleted, $credential);
        });

        $this->tokens->forget($this->tenantId(), (string) $credential->getKey());
    }

    /**
     * Whether an update changes the client an `oauth2_authorization_code` grant was given to.
     *
     * @param  array<string, string>  $settings  the settings about to be saved
     */
    private static function grantBoundSettingsChanged(Credential $credential, array $settings): bool
    {
        if (OAuth2AuthorizationCodeType::ID !== $credential->type) {
            return false;
        }

        foreach (OAuth2AuthorizationCodeType::GRANT_BOUND_SETTINGS as $key) {
            if (($credential->settings[$key] ?? null) !== ($settings[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the new list lets the secret reach a host the old one did not ({@see HostMatcher::covers()}): an added
     * host, or a wildcard over hosts only some of which were listed. Removing a host, or replacing hosts by a
     * wildcard the old list already had, is not.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    private static function hostsWidened(array $old, array $new): bool
    {
        foreach ($new as $pattern) {
            if (!HostMatcher::covers($pattern, $old)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the token URL, where the client secret (and a refresh token) is sent, moves to another host.
     *
     * @param  array<string, string>  $settings  the settings about to be saved
     */
    private static function tokenHostMoved(Credential $credential, array $settings): bool
    {
        $old = $credential->settings[self::TOKEN_URL_SETTING] ?? null;
        $new = $settings[self::TOKEN_URL_SETTING] ?? null;

        if (!is_string($new) || '' === $new) {
            return false;
        }

        $newHost = parse_url($new, PHP_URL_HOST);
        $oldHost = is_string($old) ? parse_url($old, PHP_URL_HOST) : null;

        if (!is_string($oldHost) || '' === $oldHost) {
            return true;
        }

        return !is_string($newHost) || HostMatcher::normalize($newHost) !== HostMatcher::normalize($oldHost);
    }

    /**
     * The settings under `settings.<key>`, so the audit marks each changed one by name.
     *
     * @param  array<string, mixed>  $settings
     *
     * @return array<string, mixed>
     */
    private static function settingsFlat(array $settings): array
    {
        $flat = [];

        foreach ($settings as $key => $value) {
            $flat[self::SETTINGS_MARKER . $key] = $value;
        }

        return $flat;
    }

    /**
     * @param  array<string, string>  $values
     *
     * @return array<string, string>
     */
    private static function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * The saved secrets; none when they cannot be decrypted (the application key changed), so the admin can enter
     * them again.
     *
     * @return array<string, string>
     */
    private function storedSecrets(Credential $credential): array
    {
        try {
            return array_filter($credential->secret, is_string(...));
        } catch (DecryptException) {
            // Eloquent decrypts the original to tell whether the secret is dirty; with nothing readable there it asks
            // no more, and a new secret is written over the unreadable one.
            $credential->setRawAttributes([...$credential->getAttributes(), 'secret' => null], true);

            return [];
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function failOn(array $errors): void
    {
        if ([] !== $errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validName(mixed $value, ?Credential $existing, array &$errors): string
    {
        $name = is_string($value) ? mb_trim($value) : '';

        if ('' === $name) {
            $errors['name'] = __('credential_validation.name_required');

            return '';
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = __('credential_validation.name_too_long', ['max' => self::MAX_NAME_LENGTH]);

            return $name;
        }

        $taken = $this->query()->where('name', $name);

        if (null !== $existing) {
            $taken->whereKeyNot($existing->getKey());
        }

        if ($taken->exists()) {
            $errors['name'] = __('credential_validation.name_taken');
        }

        return $name;
    }

    /**
     * @param  array<string, string>  $errors
     *
     * @return list<string> normalized and without repeats
     */
    private function validHosts(mixed $value, array &$errors): array
    {
        $entries = is_array($value) ? array_values($value) : [];

        if ([] === $entries) {
            $errors['allowed_hosts'] = __('credential_validation.hosts_required');

            return [];
        }

        if (count($entries) > self::MAX_HOSTS) {
            $errors['allowed_hosts'] = __('credential_validation.hosts_too_many', ['max' => self::MAX_HOSTS]);

            return [];
        }

        $hosts = [];

        foreach ($entries as $index => $entry) {
            if (!is_string($entry) || !HostMatcher::isValidPattern($entry)) {
                $errors["allowed_hosts.{$index}"] = __('credential_validation.host_invalid');

                continue;
            }

            $hosts[HostMatcher::normalize($entry)] = true;
        }

        return array_map('strval', array_keys($hosts));
    }

    /**
     * @param  list<CredentialField>  $fields
     * @param  list<string>  $hosts
     * @param  array<string, string>  $errors
     *
     * @return array<string, string> only the non-empty values of the type's own settings
     */
    private function validSettings(?HttpCredentialTypeInterface $type, array $fields, mixed $input, array $hosts, array &$errors): array
    {
        $input    = is_array($input) ? $input : [];
        $settings = [];

        foreach ($fields as $field) {
            if ($field->secret) {
                continue;
            }

            $path  = "settings.{$field->key}";
            $raw   = $input[$field->key] ?? null;
            $value = is_string($raw) ? mb_trim($raw) : '';

            if (null !== $raw && !is_string($raw)) {
                $errors[$path] = __('credential_validation.field_invalid');

                continue;
            }

            if ('' === $value) {
                if ($field->required) {
                    $errors[$path] = __('credential_validation.field_required');
                }

                continue;
            }

            $error = $this->settingError($field, $value, $hosts);

            if (null === $error && $type instanceof ValidatesOwnSettingsInterface) {
                $error = $type->settingError($field->key, $value);
            }

            if (null !== $error) {
                $errors[$path] = $error;

                continue;
            }

            $settings[$field->key] = $value;
        }

        return $settings;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function settingError(CredentialField $field, string $value, array $hosts): ?string
    {
        if (mb_strlen($value) > self::MAX_SETTING_LENGTH) {
            return __('credential_validation.field_too_long', ['max' => self::MAX_SETTING_LENGTH]);
        }

        if (CredentialFieldKind::Select === $field->kind) {
            return in_array($value, $field->choices, true) ? null : __('credential_validation.select_invalid');
        }

        if (CredentialFieldKind::Url !== $field->kind) {
            return null;
        }

        $parts = false !== filter_var($value, FILTER_VALIDATE_URL) ? parse_url($value) : false;

        // No user info: a secret in the URL would be stored and shown as a setting.
        if (!is_array($parts) || 'https' !== mb_strtolower((string) ($parts['scheme'] ?? '')) || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            return __('credential_validation.url_invalid');
        }

        return HostMatcher::matches($parts['host'], $hosts) ? null : __('credential_validation.url_host_not_allowed');
    }

    /**
     * @param  list<CredentialField>  $fields
     * @param  array<string, mixed>  $stored  the secrets already saved, on an update
     * @param  array<string, string>  $errors
     * @param  bool  $reenter  the secret may now reach a host it could not before: a stored secret is not kept, every
     *                         secret field must be entered again
     *
     * @return array<string, string> the secrets to keep: stored ones the input leaves alone, and the new ones
     */
    private function validSecrets(array $fields, mixed $input, array $stored, array &$errors, bool $reenter = false): array
    {
        $input   = is_array($input) ? $input : [];
        $secrets = [];

        foreach ($fields as $field) {
            if (!$field->secret) {
                continue;
            }

            $path = "secrets.{$field->key}";
            $raw  = $input[$field->key] ?? null;

            if (null !== $raw && !is_string($raw)) {
                $errors[$path] = __('credential_validation.field_invalid');

                continue;
            }

            if (null === $raw || '' === $raw) {
                $kept = $stored[$field->key] ?? null;

                if (is_string($kept) && '' !== $kept && $reenter) {
                    $errors[$path] = __('credential_validation.secret_reenter_for_new_host');
                } elseif (is_string($kept) && '' !== $kept) {
                    $secrets[$field->key] = $kept;
                } elseif ($field->required) {
                    $errors[$path] = __('credential_validation.secret_required');
                }

                continue;
            }

            if (mb_strlen($raw) < self::MIN_SECRET_LENGTH) {
                $errors[$path] = __('credential_validation.secret_too_short', ['min' => self::MIN_SECRET_LENGTH]);

                continue;
            }

            $secrets[$field->key] = $raw;
        }

        return $secrets;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(AuditAction $action, Credential $credential, ?AuditChanges $changes = null, array $properties = []): void
    {
        $this->audit->record(new AuditRecord(
            action: $action,
            subject: $credential,
            subjectLabel: $credential->name,
            changes: $changes,
            properties: $properties,
        ));
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
