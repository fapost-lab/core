<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an ad-hoc `call` node test invocation issued from the builder.
 */
final class CallTestRequest extends FormRequest
{
    /**
     * Testing a `call` node is part of authoring flows, so it needs the flow-draft permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', FlowDraft::class) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'config'                   => ['required', 'array'],
            'config.transport'         => ['nullable', 'string'],
            'config.target'            => ['nullable', 'string'],
            'config.parameters'        => ['nullable', 'array'],
            'config.transport_options' => ['nullable', 'array'],
            'sample'                   => ['nullable', 'array'],
        ];
    }
}
