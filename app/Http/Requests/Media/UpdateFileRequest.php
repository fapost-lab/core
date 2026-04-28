<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'string', 'min:1', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }
}
