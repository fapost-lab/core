<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Media\Models\MediaFolder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Where a deleted folder's files and subfolders go: another folder, or the root when none is given.
 */
final class DeleteMediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MediaFolder::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'move_to' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function moveTo(): ?string
    {
        $id = $this->validated('move_to');

        return is_string($id) ? $id : null;
    }
}
