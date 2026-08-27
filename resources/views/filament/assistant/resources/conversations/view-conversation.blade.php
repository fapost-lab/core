<x-filament-panels::page>
    @php
        /** @var \App\Domains\Conversation\Models\Conversation $conversation */
        /** @var \Illuminate\Support\Collection $messages */
        $lastDate = null;
    @endphp

    <div class="mx-auto w-full max-w-3xl" wire:poll.30s>
        {{-- Thread header --}}
        <div class="mb-4 flex items-center justify-between border-b border-gray-200 pb-3 dark:border-gray-700">
            <div>
                <div class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $contactLabel }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ ucfirst($conversation->platform) }}
                    &middot; {{ trans_choice('conversation.message_count', $conversation->message_count, ['count' => $conversation->message_count]) }}
                    &middot; {{ $ownerLabel }}
                </div>
            </div>
            <span @class([
                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $conversation->status->value === 'open',
                'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' => $conversation->status->value === 'snoozed',
                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' => $conversation->status->value === 'closed',
            ])>
                {{ __('conversation.statuses.' . $conversation->status->value) }}
            </span>
        </div>

        {{-- Load older messages --}}
        @if ($hasMoreMessages)
            <div class="mb-4 flex justify-center">
                <button
                    type="button"
                    wire:click="loadOlderMessages"
                    class="rounded-full border border-gray-200 px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                    {{ __('conversation.load_older') }}
                </button>
            </div>
        @endif

        {{-- Message stream --}}
        <div class="space-y-2">
            @forelse ($messages as $message)
                @php
                    $isOutbound = $message->direction->value === 'outbound';
                    $date = optional($message->created_at)->format('Y-m-d');
                @endphp

                @if ($date !== $lastDate)
                    <div class="my-4 flex items-center justify-center">
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            {{ optional($message->created_at)->isToday() ? __('conversation.today') : optional($message->created_at)->translatedFormat('d MMM Y') }}
                        </span>
                    </div>
                    @php $lastDate = $date; @endphp
                @endif

                <div @class(['flex', 'justify-end' => $isOutbound, 'justify-start' => ! $isOutbound])>
                    <div @class([
                        'max-w-[75%] rounded-2xl px-4 py-2 text-sm shadow-sm',
                        'rounded-br-sm bg-primary-600 text-white' => $isOutbound,
                        'rounded-bl-sm border border-gray-200 bg-white text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100' => ! $isOutbound,
                    ])>
                        {{-- Non-text content marker --}}
                        @if ($message->content_type->value !== 'text')
                            <div @class([
                                'mb-1 inline-flex items-center gap-1 text-xs font-medium',
                                'text-primary-100' => $isOutbound,
                                'text-gray-500 dark:text-gray-400' => ! $isOutbound,
                            ])>
                                <span>&#128206;</span>
                                <span>{{ __('conversation.content_types.' . $message->content_type->value) }}</span>
                            </div>
                        @endif

                        {{-- Text body --}}
                        @if (filled($message->text))
                            <div class="whitespace-pre-wrap break-words">{{ $message->text }}</div>
                        @endif

                        {{-- Media descriptors --}}
                        @if (is_array($message->media) && count($message->media) > 0)
                            <div class="mt-1 space-y-1">
                                @foreach ($message->media as $item)
                                    @php
                                        $status = $item['status'] ?? null;
                                        $mediaFileId = $item['media_file_id'] ?? null;
                                        $mediaUrl = 'ready' === $status && is_string($mediaFileId) ? $this->mediaUrl($mediaFileId) : null;
                                    @endphp

                                    @if ('ready' === $status && is_string($mediaUrl) && 'image' === ($item['kind'] ?? null))
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="block">
                                            <img
                                                src="{{ $mediaUrl }}"
                                                alt="{{ $item['file_name'] ?? __('conversation.attachment') }}"
                                                class="max-h-64 max-w-full rounded-lg object-contain"
                                            >
                                        </a>
                                    @elseif ('ready' === $status && is_string($mediaUrl))
                                        <a
                                            href="{{ $mediaUrl }}"
                                            target="_blank"
                                            rel="noopener"
                                            @class([
                                                'flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs underline',
                                                'bg-primary-500/40' => $isOutbound,
                                                'bg-gray-100 dark:bg-gray-700/60' => ! $isOutbound,
                                            ])
                                        >
                                            <span>&#128196;</span>
                                            <span class="truncate">{{ $item['file_name'] ?? __('conversation.attachment') }}</span>
                                        </a>
                                    @else
                                        <div @class([
                                            'flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs',
                                            'bg-primary-500/40' => $isOutbound,
                                            'bg-gray-100 dark:bg-gray-700/60' => ! $isOutbound,
                                        ])>
                                            <span>&#128196;</span>
                                            <span class="truncate">
                                                {{ $item['file_name'] ?? ($item['kind'] ?? __('conversation.attachment')) }}
                                                @if ('pending' === $status)
                                                    &middot; {{ __('conversation.media.pending') }}
                                                @elseif ('failed' === $status)
                                                    &middot; {{ __('conversation.media.failed') }}
                                                @endif
                                            </span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        {{-- Inline keyboard hint --}}
                        @php $keyboard = $message->payload['keyboard'] ?? null; @endphp
                        @if (is_array($keyboard))
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach (\Illuminate\Support\Arr::flatten($keyboard, 1) as $button)
                                    @php $label = is_array($button) ? ($button['text'] ?? $button['label'] ?? null) : (is_string($button) ? $button : null); @endphp
                                    @if (filled($label))
                                        <span @class([
                                            'rounded-md px-2 py-0.5 text-xs',
                                            'bg-white/20 text-white' => $isOutbound,
                                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $isOutbound,
                                        ])>{{ $label }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        {{-- Meta: time + delivery status --}}
                        <div @class([
                            'mt-1 flex items-center justify-end gap-1 text-[0.65rem]',
                            'text-primary-100' => $isOutbound,
                            'text-gray-400' => ! $isOutbound,
                        ])>
                            <span>{{ optional($message->created_at)->format('H:i') }}</span>
                            @if ($isOutbound)
                                <span title="{{ __('conversation.delivery.' . $message->status->value) }}">
                                    @switch($message->status->value)
                                        @case('read') &#10003;&#10003; @break
                                        @case('delivered') &#10003;&#10003; @break
                                        @case('failed') &#10005; @break
                                        @default &#10003;
                                    @endswitch
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('conversation.empty') }}
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
