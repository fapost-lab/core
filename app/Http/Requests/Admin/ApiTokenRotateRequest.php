<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Services\ApiTokenService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a rotation may change: the abilities the successor keeps (never more than the predecessor had) and its
 * lifetime. This checks shape only; {@see ApiTokenService} decides the rest.
 */
final class ApiTokenRotateRequest extends FormRequest
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
            'abilities'       => ['nullable', 'array'],
            'abilities.*'     => ['string'],
            'expires_in_days' => ['nullable', 'integer'],
        ];
    }
}
