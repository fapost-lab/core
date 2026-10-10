<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Media\Models\MediaFolder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A folder's name and, when it is created, its parent (none is the root). The name follows the REST rules: no slash,
 * since the name is a segment of the folder's path.
 */
final class MediaFolderRequest extends FormRequest
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
            'name'      => ['required', 'string', 'max:' . (int) config('media.folder.name_max_chars', 255), 'not_regex:/[\/\\\\]/'],
            'parent_id' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function name(): string
    {
        return mb_trim((string) $this->validated('name'));
    }

    public function parentId(): ?string
    {
        $id = $this->validated('parent_id');

        return is_string($id) ? $id : null;
    }
}
