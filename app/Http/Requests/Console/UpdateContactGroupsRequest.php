<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Services\AssistantContactService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The groups a contact is to be in: the whole set, so repeating the request changes nothing. An empty set takes the
 * contact out of every group. A group of another tenant is refused, not skipped.
 */
final class UpdateContactGroupsRequest extends FormRequest
{
    /**
     * Needs `update` on the contact in the route (a contact the assistant does not see is a 404).
     */
    public function authorize(AssistantContactService $contacts, CurrentAssistantInterface $assistant): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        return $user->can('update', $contacts->findFor($assistant->get(), (string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContextInterface $tenants): array
    {
        return [
            'groups'   => ['present', 'array'],
            'groups.*' => [
                'string',
                'uuid',
                'distinct',
                Rule::exists('contact_groups', 'id')->where('tenant_id', $tenants->get()->getId()),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function groupIds(): array
    {
        /** @var list<string> $groups */
        $groups = array_values($this->validated('groups'));

        return $groups;
    }
}
