<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a flow, for creating one (no `record` in the route) and for changing one. The graph is not among them:
 * it belongs to the builder.
 */
final class FlowRequest extends FormRequest
{
    /**
     * Changing needs `update` on the flow in the route (a flow of another assistant is a 404), creating needs `create`.
     */
    public function authorize(FlowDraftService $drafts): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', FlowDraft::class);
        }

        return $user->can('update', $drafts->findForAssistant((string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantContextInterface $tenants, CurrentAssistantInterface $assistant): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Only a group of this assistant: the column has no foreign key to hold it to that.
            'flow_group_id' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('flow_groups', 'id')
                    ->where('tenant_id', $tenants->get()->getId())
                    ->where('assistant_id', (string) $assistant->get()->getKey()),
            ],
            'description'     => ['nullable', 'string', 'max:65535'],
            'is_public'       => ['required', 'boolean'],
            'logging_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, flow_group_id: string|null, description: string|null, is_public: bool, logging_enabled: bool}
     */
    public function fields(): array
    {
        /** @var array{name: string, flow_group_id?: string|null, description?: string|null} $data */
        $data = $this->validated();

        return [
            'name'            => $data['name'],
            'flow_group_id'   => $data['flow_group_id'] ?? null,
            'description'     => $data['description'] ?? null,
            'is_public'       => $this->boolean('is_public'),
            'logging_enabled' => $this->boolean('logging_enabled'),
        ];
    }
}
