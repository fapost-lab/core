<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Services\FlowGroupService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The fields of a flow group, for creating one (no `record` in the route, the inline form of a flow included) and for
 * changing one.
 */
final class FlowGroupRequest extends FormRequest
{
    /**
     * Changing needs `update` on the group in the route (a group of another assistant is a 404), creating needs `create`.
     */
    public function authorize(FlowGroupService $groups): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', FlowGroup::class);
        }

        return $user->can('update', $groups->findForAssistant((string) $this->route('record')));
    }

    /**
     * Names are not unique: the table has no unique index on them.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
