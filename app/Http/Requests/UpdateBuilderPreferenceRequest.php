<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domains\Staff\Enums\BuilderMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBuilderPreferenceRequest extends FormRequest
{
    /**
     * Authorization is the controller's: it checks the flow-draft permission before anything is stored.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'builder_mode' => ['required', Rule::enum(BuilderMode::class)],
        ];
    }
}
