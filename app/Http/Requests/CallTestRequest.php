<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an ad-hoc `call` node test invocation issued from the builder.
 */
final class CallTestRequest extends FormRequest
{
    /**
     * Testing a `call` node is part of authoring flows, so it needs the flow-draft permission. A test that names a saved
     * credential spends its secret against the target, so it also needs the right to use credentials.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (null === $user || ! $user->can('create', FlowDraft::class)) {
            return false;
        }

        $credentialId = $this->input('config.credential_id');

        return null === $credentialId || '' === $credentialId || $user->can('use', Credential::class);
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
            'config.credential_id'     => ['nullable', 'string'],
            'config.success_statuses'  => ['nullable', 'array'],
            'sample'                   => ['nullable', 'array'],
        ];
    }
}
