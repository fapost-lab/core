<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Media\Models\MediaFile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Media files picked from the list for one action (move, delete), and for a move the folder they go to (none is the
 * root). Each file is still authorized on its own.
 */
final class MediaFileIdsRequest extends FormRequest
{
    public const int MAX_IDS = 100;

    public function authorize(): bool
    {
        // Any write on a file needs the manage permission; `create` asks exactly that without a record.
        return $this->user()?->can('create', MediaFile::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids'       => ['required', 'array', 'min:1', 'max:' . self::MAX_IDS],
            'ids.*'     => ['required', 'string', 'uuid'],
            'folder_id' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('ids')));
    }

    public function folderId(): ?string
    {
        $id = $this->validated('folder_id');

        return is_string($id) ? $id : null;
    }
}
