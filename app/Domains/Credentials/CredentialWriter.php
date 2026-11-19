<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
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
 * Each write and its audit entry commit together. The entry names the credential's name, type and hosts and the fact
 * that settings changed or the secret was replaced; no setting or secret value is recorded. After an update or a
 * delete has committed, the cached OAuth2 token of the credential is dropped.
 */
final readonly class CredentialWriter
{
    public const int MIN_SECRET_LENGTH = 8;

    public const int MAX_HOSTS = 20;

    public const int MAX_NAME_LENGTH = 120;

    public const int MAX_SETTING_LENGTH = 255;

    public function __construct(
        private TenantContextInterface $tenants,
        private CredentialTypeRegistry $types,
        private OAuth2TokenCache $tokens,
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

        $fields = $this->types->get($typeId)->fields();
        $errors = [];

        $name     = $this->validName($data['name'] ?? null, null, $errors);
        $hosts    = $this->validHosts($data['allowed_hosts'] ?? null, $errors);
        $settings = $this->validSettings($fields, $data['settings'] ?? null, $hosts, $errors);
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
        $fields = $this->types->has($credential->type) ? $this->types->get($credential->type)->fields() : [];
        $errors = [];

        $name     = $this->validName($data['name'] ?? null, $credential, $errors);
        $hosts    = $this->validHosts($data['allowed_hosts'] ?? null, $errors);
        $settings = $this->validSettings($fields, $data['settings'] ?? null, $hosts, $errors);
        $stored   = $this->storedSecrets($credential);
        $secrets  = $this->validSecrets($fields, $data['secrets'] ?? null, $stored, $errors);

        $this->failOn($errors);

        $secretReplaced = self::sorted($secrets) !== self::sorted($stored);

        try {
            DB::transaction(function () use ($credential, $name, $settings, $hosts, $secrets, $secretReplaced): void {
                $before = ['name' => $credential->name, 'allowed_hosts' => $credential->allowed_hosts, 'settings' => $credential->settings];

                $credential->fill(['name' => $name, 'settings' => $settings, 'allowed_hosts' => $hosts]);

                if ($secretReplaced) {
                    $credential->fill(['secret' => $secrets, 'secret_changed_at' => Carbon::now()]);
                }

                $credential->save();

                $changes = AuditChanges::between(
                    $before,
                    ['name' => $credential->name, 'allowed_hosts' => $credential->allowed_hosts, 'settings' => $credential->settings],
                    ['name', 'allowed_hosts'],
                    ['settings'],
                );

                if (!$changes->isEmpty() || $secretReplaced) {
                    $this->record(AuditAction::CredentialUpdated, $credential, $changes, ['secret_replaced' => $secretReplaced]);
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
            $credential->delete();

            $this->record(AuditAction::CredentialDeleted, $credential);
        });

        $this->tokens->forget($this->tenantId(), (string) $credential->getKey());
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
    private function validSettings(array $fields, mixed $input, array $hosts, array &$errors): array
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
     *
     * @return array<string, string> the secrets to keep: stored ones the input leaves alone, and the new ones
     */
    private function validSecrets(array $fields, mixed $input, array $stored, array &$errors): array
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

                if (is_string($kept) && '' !== $kept) {
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
