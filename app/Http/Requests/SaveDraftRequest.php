<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'draft_version' => ['required', 'integer', 'min:1'],
            'definition'    => ['required', 'array'],
            'trigger'       => ['nullable', 'array'],
        ];
    }
}
