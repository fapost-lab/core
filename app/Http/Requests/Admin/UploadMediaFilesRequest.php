<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Media\Models\MediaFile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Files uploaded to the media library in one go, with the same size and type limits as the REST upload
 * (`config/media.php`); one file outside them refuses the batch before anything is stored.
 */
final class UploadMediaFilesRequest extends FormRequest
{
    public const int MAX_FILES = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('create', MediaFile::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $allowedMimes = (array) config('media.allowed_mime_types', []);
        $file         = ['required', 'file', 'max:' . $this->maxKilobytes()];

        if ([] !== $allowedMimes) {
            $file[] = 'mimetypes:' . implode(',', $allowedMimes);
        }

        return [
            'files'     => ['required', 'array', 'min:1', 'max:' . self::MAX_FILES],
            'files.*'   => $file,
            'folder_id' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.*.max' => __('media.errors.file_too_large', ['max' => round($this->maxKilobytes() / 1024)]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['files.*' => __('media.fields.file')];
    }

    /**
     * @return list<\Illuminate\Http\UploadedFile>
     */
    public function uploads(): array
    {
        return array_values((array) $this->file('files', []));
    }

    public function folderId(): ?string
    {
        $id = $this->validated('folder_id');

        return is_string($id) ? $id : null;
    }

    private function maxKilobytes(): int
    {
        return (int) max(1, (int) config('media.max_size_bytes', 100 * 1024 * 1024) / 1024);
    }
}
