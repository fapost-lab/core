<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Services\FlowSessionInspector;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A re-run from the start of a failed automation run: the attempt the person confirmed, so a repeated or replayed
 * request carries the same one and starts no second run. Needs `rerun` on the session; whether the run can be re-run
 * now is decided by {@see \App\Domains\Flow\Services\AutomationRunRerunService::rerun()}.
 */
final class RerunFlowSessionRequest extends FormRequest
{
    public function authorize(FlowSessionInspector $sessions, CurrentAssistantInterface $assistant): bool
    {
        return $this->user()?->can('rerun', $sessions->findFor($assistant->get(), (string) $this->route('record'))) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'attempt' => ['required', 'integer', 'min:1'],
        ];
    }

    public function attempt(): int
    {
        return (int) $this->validated('attempt');
    }
}
