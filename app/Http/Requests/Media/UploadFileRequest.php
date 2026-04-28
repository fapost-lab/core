<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $maxKilobytes = (int)max(1, (int)config('media.max_size_bytes', 100 * 1024 * 1024) / 1024);

        $allowedMimes = config('media.allowed_mime_types', []);

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxKilobytes,
                Rule::when(
                    is_array($allowedMimes) && [] !== $allowedMimes,
                    ['mimetypes:' . implode(',', (array)$allowedMimes)]
                ),
            ],
            'folder_id' => ['nullable', 'string', 'uuid'],
            'name'      => ['nullable', 'string', 'max:255'],
        ];
    }
}
