<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Services\AssistantContactService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The tags a contact is to have: the whole set, so repeating the request changes nothing. An empty set removes every tag.
 * Blank and repeated tags are dropped when the set is stored.
 */
final class UpdateContactTagsRequest extends FormRequest
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
     * No limit on how many: a flow may set any number, and a limit here would make such a contact's card unsavable.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'tags' => ['present', 'array'],
            // `contact_tags.tag` is `string(255)`.
            // An empty string reaches validation as null (the middleware trims it that way); it is a blank tag, dropped below.
            'tags.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_values(array_filter($this->validated('tags'), is_string(...)));
    }
}
