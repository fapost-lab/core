<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Services\BroadcastService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Validation\Rule;

/**
 * The rules for the audience of a broadcast, shared by the form that saves it and the request that counts its reach,
 * so the two accept the same audiences.
 *
 * Only the fields the chosen target uses are validated and kept (`exclude_unless`); a tag must be one contacts carry,
 * a segment must be the tenant's own, because the column holds no foreign key to hold it to that.
 */
final class BroadcastAudienceRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(BroadcastService $broadcasts, TenantContextInterface $tenants): array
    {
        return [
            'target_type'       => ['required', Rule::enum(BroadcastTarget::class)],
            'target_tags'       => ['exclude_unless:target_type,' . BroadcastTarget::Tags->value, 'required', 'array', 'min:1'],
            'target_tags.*'     => ['string', 'distinct', Rule::in($broadcasts->tagOptions())],
            'target_segment_id' => [
                'exclude_unless:target_type,' . BroadcastTarget::Segment->value,
                'required',
                'string',
                'uuid',
                Rule::exists('contact_segments', 'id')->where('tenant_id', $tenants->get()->getId()),
            ],
        ];
    }
}
