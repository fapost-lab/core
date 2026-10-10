<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use App\Http\Shell\AdminSearch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A search from the admin shell's palette. Only a user who may list at least one of the searched kinds may search.
 */
final class SearchRequest extends FormRequest
{
    public const int MAX_LENGTH = 100;

    public function authorize(AdminSearch $search): bool
    {
        $user = $this->user();

        return $user instanceof User && $search->isAvailableTo($user);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:' . self::MAX_LENGTH],
        ];
    }

    public function text(): string
    {
        return (string) $this->validated('q', '');
    }
}
