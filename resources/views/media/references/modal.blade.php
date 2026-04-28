@props(['references'])

<div class="space-y-3">
    @forelse ($references as $reference)
        @php
            $snapshot = is_array($reference->snapshot) ? $reference->snapshot : [];
        @endphp
        <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3">
            <div class="text-xs text-gray-500 uppercase tracking-wide">
                {{ __('media.references.types.' . $reference->reference_type, [], $reference->reference_type) }}
            </div>
            <div class="mt-1 font-medium text-gray-900 dark:text-gray-100">
                {{ $snapshot['flow_name'] ?? $reference->reference_id }}
            </div>
            @if (isset($snapshot['node_label']) || isset($snapshot['node_id']))
                <div class="mt-1 text-sm text-gray-500">
                    {{ __('media.references.node') }}:
                    {{ $snapshot['node_label'] ?? $snapshot['node_id'] }}
                </div>
            @endif
        </div>
    @empty
        <div class="text-sm text-gray-500">
            {{ __('media.references.empty') }}
        </div>
    @endforelse
</div>
