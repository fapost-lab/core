<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an ad-hoc `call` node test invocation issued from the builder.
 */
final class CallTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
