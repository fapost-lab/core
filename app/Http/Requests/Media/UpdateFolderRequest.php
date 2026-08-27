<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $maxNameChars = (int)config('media.folder.name_max_chars', 255);

        return [
            'name'      => ['sometimes', 'string', 'min:1', 'max:' . $maxNameChars, 'not_regex:/[\/\\\\]/'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }
}
