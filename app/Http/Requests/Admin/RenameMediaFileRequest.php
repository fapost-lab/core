<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Media\Models\MediaFile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A media file's new name; the record itself is authorized by the controller once it is found in the tenant.
 */
final class RenameMediaFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MediaFile::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function name(): string
    {
        return mb_trim((string) $this->validated('name'));
    }
}
