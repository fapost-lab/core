<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The fields of a new flow template: a name and an optional description. A template has no group, visibility or
 * logging of its own; the graph belongs to the builder.
 */
final class FlowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FlowDraft::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * @return array{name: string, description: string|null}
     */
    public function fields(): array
    {
        /** @var array{name: string, description?: string|null} $data */
        $data = $this->validated();

        return [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ];
    }
}
