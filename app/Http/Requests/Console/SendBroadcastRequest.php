<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Broadcasting\Services\BroadcastService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sending a draft: the revision of the broadcast the person confirmed. A request without one is refused, so a bare
 * POST cannot start a broadcast nobody looked at.
 */
final class SendBroadcastRequest extends FormRequest
{
    /**
     * Needs `send` on the broadcast in the route (a broadcast of another assistant is a 404).
     */
    public function authorize(BroadcastService $broadcasts): bool
    {
        return $this->user()?->can('send', $broadcasts->findForAssistant((string) $this->route('record'))) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'size:64'],
        ];
    }

    public function revision(): string
    {
        return (string) $this->validated('revision');
    }
}
