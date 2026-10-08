<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use Illuminate\Foundation\Http\FormRequest;

final class SaveDraftRequest extends FormRequest
{
    /**
     * The user must be able to edit the draft of the flow in the route; a missing flow is a 404.
     */
    public function authorize(): bool
    {
        $draft = app(FlowDraftRepositoryInterface::class)->findByFlowId((string) $this->route('flow'));

        return $this->user()?->can('update', $draft) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'draft_version' => ['required', 'integer', 'min:1'],
            'definition'    => ['required', 'array'],
            'trigger'       => ['nullable', 'array'],
        ];
    }
}
