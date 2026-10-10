<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The audience whose reach is asked for, as the form holds it now (not saved yet). Whoever may create a broadcast may ask.
 */
final class BroadcastReachRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Broadcast::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(BroadcastService $broadcasts, TenantContextInterface $tenants): array
    {
        return BroadcastAudienceRules::rules($broadcasts, $tenants);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return BroadcastRequest::attributeNames();
    }
}
