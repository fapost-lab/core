<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\WorkspaceDeletion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The admin's request to delete the workspace: allowed only on an installation that resolves tenants by host, where the
 * operator's policy says so, and to an administrator of the tenant (never the platform support user), and only with the
 * workspace's slug typed in.
 */
final class RequestWorkspaceDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(WorkspaceDeletion::class)->mayRequest((string) $user->getKey(), app(TenantContextInterface::class)->get());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['slug' => ['required', 'string']];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(WorkspaceDeletion $deletion, TenantContextInterface $context): array
    {
        return [
            function (Validator $validator) use ($deletion, $context): void {
                if (! $validator->errors()->has('slug') && ! $deletion->confirms($context->get(), $this->string('slug')->toString())) {
                    $validator->errors()->add('slug', trans('console.tenant_settings.delete.slug_mismatch'));
                }
            },
        ];
    }
}
