<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Services\StaffUserService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The state a staff account is to be put in: active or deactivated. The state is sent, not "switch it", so repeating
 * the request changes nothing. Each direction needs its own ability (`activate`, `deactivate`).
 */
final class UpdateStaffUserActivityRequest extends FormRequest
{
    public function authorize(StaffUserService $users): bool
    {
        $target = $users->find((string) $this->route('record'));

        return $this->user()?->can($this->active() ? 'activate' : 'deactivate', $target) ?? false;
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
