<x-filament-panels::page>
    @php
        /** @var \App\Domains\Conversation\Models\Conversation $conversation */
        /** @var \Illuminate\Support\Collection $messages */
        $lastDate = null;
    @endphp

    {{-- Height is measured rather than guessed: the panel chrome above this
         point (topbar, breadcrumb, heading) is not a constant we can hardcode,
         and being a few rem off clips the composer off-screen. --}}
    <div
        x-data="{
            height: null,
            fit() { this.height = Math.max(320, window.innerHeight - $el.getBoundingClientRect().top - 24) },
        }"
        x-init="fit(); $nextTick(() => fit())"
        x-on:resize.window="fit()"
        :style="height ? `height: ${height}px` : null"
        class="mx-auto flex w-full max-w-5xl flex-col"
        wire:poll.30s
    >
        {{-- Thread header --}}
        <div class="mb-4 flex shrink-0 items-center justify-between border-b border-gray-200 pb-3 dark:border-gray-700">
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

        {{-- Message stream. Framed as its own panel so the thread reads as a
             chat window with a beginning and an end, and scrolled internally —
             the page header and the composer stay put while the conversation
             moves, the way every chat client behaves. --}}
        <div
            x-data="{ toBottom() { $nextTick(() => { $el.scrollTop = $el.scrollHeight }) } }"
            x-init="toBottom()"
            x-on:conversation-updated.window="toBottom()"
            class="min-h-0 flex-1 space-y-2 overflow-y-auto rounded-xl border border-gray-200 bg-white/60 p-4 dark:border-gray-700 dark:bg-gray-900/40"
        >
            @forelse ($messages as $message)
                @php
                    $isOutbound = $message->direction->value === 'outbound';
                    // Outbound is not one voice: the flow engine and an operator
                    // who took the thread over both write here, and an operator
                    // reply must never read as something the bot said.
                    $isStaff = $message->sender_type->value === 'staff';
                    $date = optional($message->created_at)->format('Y-m-d');

                    // Avatar mirrors the takeover action's icon for operators, so
                    // the same hand means "a human has this thread" everywhere.
                    $avatarIcon = match ($message->sender_type->value) {
                        'contact'   => 'heroicon-o-user',
                        'assistant' => 'heroicon-o-cpu-chip',
                        'staff'     => 'heroicon-o-hand-raised',
                        default     => 'heroicon-o-cog-6-tooth',
                    };
                @endphp

                @if ($date !== $lastDate)
                    <div class="my-4 flex items-center justify-center">
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            {{ optional($message->created_at)->isToday() ? __('conversation.today') : optional($message->created_at)->translatedFormat('d MMM Y') }}
                        </span>
                    </div>
                    @php $lastDate = $date; @endphp
                @endif

                {{-- Bubbles run nearly the full width of the frame: this is a
                     staff transcript, read top-to-bottom, so a stable text
                     column beats chat-style bubbles that resize per message.
                     Side alignment alone can't carry authorship once operators
                     write too, so every message is signed above its bubble. --}}
                <div @class(['flex items-start gap-2', 'flex-row-reverse' => $isOutbound])>
                    {{-- Avatar: the side it sits on says inbound/outbound at a
                         glance, the glyph and colour say who exactly. --}}
                    <div @class([
                        'flex size-8 shrink-0 items-center justify-center rounded-full',
                        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' => $isStaff,
                        'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300' => $isOutbound && ! $isStaff,
                        'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $isOutbound,
                    ])>
                        @svg($avatarIcon, 'size-4')
                    </div>

                    <div class="min-w-0 flex-1">
                        <div @class([
                            'mb-1 px-1 text-xs font-medium text-gray-600 dark:text-gray-300',
                            'text-right' => $isOutbound,
                        ])>
                            {{ $this->authorLabel($message) }}
                        </div>

                    <div @class([
                        'rounded-2xl px-4 py-2 text-sm shadow-sm',
                        'rounded-br-sm border border-amber-200 bg-amber-50 text-gray-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-gray-100' => $isStaff,
                        'rounded-br-sm border border-primary-200 bg-primary-50 text-gray-900 dark:border-primary-900/40 dark:bg-primary-900/20 dark:text-gray-100' => $isOutbound && ! $isStaff,
                        'rounded-bl-sm border border-gray-200 bg-white text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100' => ! $isOutbound,
                    ])>
                        {{-- Non-text content marker --}}
                        @if ($message->content_type->value !== 'text')
                            <div
                                class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400">
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
                                            class="flex items-center gap-1.5 rounded-lg bg-black/5 px-2 py-1 text-xs underline dark:bg-white/10"
                                        >
                                            <span>&#128196;</span>
                                            <span class="truncate">{{ $item['file_name'] ?? __('conversation.attachment') }}</span>
                                        </a>
                                    @else
                                        <div class="flex items-center gap-1.5 rounded-lg bg-black/5 px-2 py-1 text-xs dark:bg-white/10">
                                            <span>&#128196;</span>
                                            <span class="truncate">
                                                {{ $item['file_name'] ?? ($item['kind'] ?? __('conversation.attachment')) }}
                                                @if ('pending' === $status)
                                                    &middot; {{ __('conversation.media.pending') }}
                                                @elseif ('failed' === $status)
                                                    &middot; {{ 'storage_limit_reached' === ($item['reason'] ?? null) ? __('conversation.media.storage_limit_reached') : __('conversation.media.failed') }}
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
                                        <span class="rounded-md bg-black/5 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $label }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        {{-- Meta: time + delivery status --}}
                        <div class="mt-1 flex items-center justify-end gap-1 text-[0.65rem] text-gray-400 dark:text-gray-500">
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
                </div>
            @empty
                <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('conversation.empty') }}
                </div>
            @endforelse
        </div>

        {{-- Composer. Writing is gated on ownership: while the flow engine owns
             the thread an operator reply would race the bot mid-scenario, so the
             input is closed until the thread is taken over, and the way out is
             offered right here. --}}
        <div class="mt-3 shrink-0 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900/40">
            @if ($this->canReplyNow())
                <textarea
                    wire:model="replyText"
                    wire:keydown.meta.enter="sendComposerReply"
                    wire:keydown.ctrl.enter="sendComposerReply"
                    rows="2"
                    class="w-full resize-y rounded-lg border border-gray-200 bg-white p-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                    placeholder="{{ __('conversation.reply.placeholder') }}"
                ></textarea>

                @error('replyText')
                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
                @error('attachment')
                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror

                {{-- Picked file, before it is sent --}}
                @if ($attachment)
                    <div class="mt-2 flex items-center gap-2 rounded-lg bg-black/5 px-2 py-1 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        @svg('heroicon-o-paper-clip', 'size-4 shrink-0')
                        <span class="truncate">{{ $attachment->getClientOriginalName() }}</span>
                        <button
                            type="button"
                            wire:click="$set('attachment', null)"
                            class="ml-auto shrink-0 text-gray-400 hover:text-danger-600"
                            title="{{ __('conversation.reply.attachment_remove') }}"
                        >&times;</button>
                    </div>
                @endif

                <div wire:loading wire:target="attachment" class="mt-2 text-xs text-gray-500">
                    {{ __('conversation.reply.attachment_uploading') }}
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                        @svg('heroicon-o-paper-clip', 'size-4')
                        {{ __('conversation.reply.attach') }}
                        <input type="file" wire:model="attachment" class="hidden">
                    </label>

                    <span class="hidden text-xs text-gray-500 sm:inline dark:text-gray-400">
                        {{ __('conversation.reply.hint') }}
                    </span>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="toggleStatus"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            @svg($conversation->status->value === 'open' ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-path', 'size-4')
                            {{ $conversation->status->value === 'open' ? __('conversation.actions.close') : __('conversation.actions.reopen') }}
                        </button>

                        <button
                            type="button"
                            wire:click="returnThreadToBot"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            @svg('heroicon-o-arrow-uturn-left', 'size-4')
                            {{ __('conversation.actions.return_to_bot') }}
                        </button>

                        <button
                            type="button"
                            wire:click="sendComposerReply"
                            wire:loading.attr="disabled"
                            wire:target="sendComposerReply,attachment"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-500 disabled:opacity-60"
                        >
                            @svg('heroicon-o-paper-airplane', 'size-4')
                            {{ __('conversation.actions.reply') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('conversation.reply.locked') }}
                    </p>

                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="toggleStatus"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                        >
                            @svg($conversation->status->value === 'open' ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-path', 'size-4')
                            {{ $conversation->status->value === 'open' ? __('conversation.actions.close') : __('conversation.actions.reopen') }}
                        </button>

                        @if ($this->canTakeOver())
                            <button
                                type="button"
                                wire:click="takeOverThread"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-400"
                            >
                                @svg('heroicon-o-hand-raised', 'size-4')
                                {{ __('conversation.actions.take_over') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
