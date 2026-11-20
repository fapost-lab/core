<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Services\ApiTokenService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The fields of a new API token. This checks shape only; what the actor may grant, the name and the lifetime are
 * decided by {@see ApiTokenService}, which answers a refusal with a 422 keyed by field.
 */
final class ApiTokenIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageApiTokens->value) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name'            => ['nullable', 'string'],
            'abilities'       => ['nullable', 'array'],
            'abilities.*'     => ['string'],
            'expires_in_days' => ['nullable', 'integer'],
        ];
    }
}
