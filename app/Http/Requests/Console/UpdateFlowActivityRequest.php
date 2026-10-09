<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Flow\Services\FlowDraftService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The state a flow is to be put in: active or not. The state itself is sent, not "switch it", so repeating the request
 * changes nothing.
 */
final class UpdateFlowActivityRequest extends FormRequest
{
    /**
     * Needs `update` on the flow in the route (a flow of another assistant is a 404).
     */
    public function authorize(FlowDraftService $drafts): bool
    {
        return $this->user()?->can('update', $drafts->findForAssistant((string) $this->route('record'))) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }

    public function active(): bool
    {
        return $this->boolean('active');
    }
}
