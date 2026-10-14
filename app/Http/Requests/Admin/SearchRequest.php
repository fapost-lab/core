<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A search from the shell's palette. Every signed-in staff user may search: the applications they may open are always
 * there to find, and each group of records is still searched only for a user who may list that kind.
 */
final class SearchRequest extends FormRequest
{
    public const int MAX_LENGTH = 100;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canAccessConsole();
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
