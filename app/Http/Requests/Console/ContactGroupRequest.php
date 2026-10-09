<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Services\ContactGroupService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a contact group, for creating one (no `record` in the route) and for changing one.
 */
final class ContactGroupRequest extends FormRequest
{
    /**
     * Changing needs `update` on the group in the route (a group of another tenant is a 404), creating needs `create`.
     */
    public function authorize(ContactGroupService $groups): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', ContactGroup::class);
        }

        return $user->can('update', $groups->findForTenant((string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContextInterface $tenants): array
    {
        return [
            // The name is unique within the tenant, as the table's unique index is.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('contact_groups', 'name')
                    ->where('tenant_id', $tenants->get()->getId())
                    ->ignore($this->route('record') ? (string) $this->route('record') : null),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
