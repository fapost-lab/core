<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\WorkspaceDeletion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling the scheduled deletion: any administrator of the tenant, never the platform support user. The operator's
 * policy is not asked, so a deletion already scheduled can be cancelled after the policy changed.
 */
final class CancelWorkspaceDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(WorkspaceDeletion::class)->mayCancel((string) $user->getKey());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
