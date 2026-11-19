<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Credentials\CredentialTypeRegistry;
use App\Domains\Credentials\CredentialWriter;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a credential, for creating one (no `record` in the route) and for changing one.
 *
 * This checks shape only; what a type's fields demand (required values, https URLs, secret length, host patterns) is
 * checked by {@see CredentialWriter}, which knows the type. The messages of both layers carry no value, and the `secrets`
 * are not flashed back to the form after a refusal: that is the exception handler's `dontFlash` (`bootstrap/app.php`),
 * the only list Laravel reads for it.
 */
final class CredentialRequest extends FormRequest
{
    /**
     * Changing needs `update` on the credential in the route (one of another tenant is a 404), creating needs `create`.
     */
    public function authorize(CredentialWriter $credentials): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', Credential::class);
        }

        return $user->can('update', $credentials->find((string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContextInterface $tenants, CredentialTypeRegistry $types): array
    {
        $record = $this->route('record');

        $rules = [
            // The name is unique within the tenant, as the table's unique index is.
            'name' => [
                'required',
                'string',
                'max:' . CredentialWriter::MAX_NAME_LENGTH,
                Rule::unique('credentials', 'name')
                    ->where('tenant_id', $tenants->get()->getId())
                    ->ignore(null !== $record ? (string) $record : null),
            ],
            'settings'        => ['nullable', 'array'],
            'settings.*'      => ['nullable', 'string', 'max:' . CredentialWriter::MAX_SETTING_LENGTH],
            'secrets'         => ['nullable', 'array'],
            'secrets.*'       => ['nullable', 'string', 'max:4096'],
            'allowed_hosts'   => ['required', 'array', 'max:' . CredentialWriter::MAX_HOSTS],
            'allowed_hosts.*' => ['string', 'max:255'],
        ];

        // The type is chosen once; a change cannot send another one.
        if (null === $record) {
            $rules['type'] = ['required', 'string', Rule::in($types->ids())];
        }

        return $rules;
    }
}
