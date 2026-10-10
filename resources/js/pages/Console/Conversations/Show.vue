<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ArrowLeft, FileText, MessageSquareOff, Paperclip, Send, X } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Textarea } from '@fapost/ui/components/textarea'
import type { LiveChannel } from '@fapost/ui/lib/live-updates'
import { useLiveUpdates } from '@fapost/ui/lib/useLiveUpdates'
import { interpolate } from '@fapost/ui/shell'
import { exceedsSize, formatSize, newRequestId } from './draft'
import LiveHint from './LiveHint.vue'
import { deliveryGlyph, groupByDay, isToday, timeLabel } from './transcript'
import { STATUS_TONES, type Abilities, type ConversationDetails, type ConversationsPageProps, type Message, type Paging } from './types'

const props = defineProps<{
  conversation: ConversationDetails
  messages: Message[]
  paging: Paging
  can: Abilities
  attachment: { maxKb: number; accept: string }
  textMax: number
  live: LiveChannel | null
  urls: { index: string; self: string; reply: string; takeOver: string; returnToBot: string; status: string }
}>()

const POLL_MS = 10_000

const page = usePage<ConversationsPageProps>()
const t = computed(() => page.props.translations.console.conversations)
const locale = computed(() => page.props.locale)

const { transport } = useLiveUpdates({
  live: () => props.live,
  only: ['conversation', 'messages', 'paging', 'can', 'navigation'],
  pollMs: POLL_MS,
})

const groups = computed(() => groupByDay(props.messages))

const ownerLine = computed(() => {
  if (props.conversation.owner !== 'staff') {
    return t.value.owner.bot
  }

  return props.conversation.ownerName ? interpolate(t.value.owner.staff_named, { name: props.conversation.ownerName }) : t.value.owner.staff
})

function dayLabel(iso: string): string {
  return isToday(iso) ? t.value.today : new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(new Date(iso))
}

function bubbleClass(message: Message): string {
  if (message.senderType === 'staff') {
    return 'bg-primary-soft text-foreground'
  }

  if (message.direction === 'outbound') {
    return 'bg-secondary text-secondary-foreground'
  }

  return 'bg-card text-card-foreground border'
}

// Transcript scrolling: to the bottom on load and when a newer message arrives; not when older ones are prepended.
const transcript = ref<HTMLElement | null>(null)

function scrollToBottom(): void {
  void nextTick(() => {
    if (transcript.value) {
      transcript.value.scrollTop = transcript.value.scrollHeight
    }
  })
}

onMounted(scrollToBottom)
watch(() => props.messages[props.messages.length - 1]?.id, scrollToBottom)

function loadOlder(): void {
  router.get(props.urls.self, { messages: props.paging.older }, { preserveScroll: true, preserveState: true, only: ['messages', 'paging'] })
}

// The composer. A draft's identity is its content: the request id changes when the text or the file does, and stays
// across retries of the same draft, so a retried request sends one message.
const form = useForm({
  text: '',
  attachment: null as File | null,
  request_id: newRequestId(),
})

watch(
  () => [form.text, form.attachment],
  () => {
    form.request_id = newRequestId()
  },
)

const fileInput = ref<HTMLInputElement | null>(null)
const attachmentError = ref<string | null>(null)

function onFileChosen(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null

  attachmentError.value = null

  if (file && exceedsSize(file.size, props.attachment.maxKb)) {
    attachmentError.value = interpolate(t.value.composer.too_large, { size: formatSize(props.attachment.maxKb) })
    input.value = ''
    form.attachment = null

    return
  }

  form.attachment = file
}

function removeAttachment(): void {
  form.attachment = null
  attachmentError.value = null

  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const canSubmit = computed(() => !form.processing && (form.text.trim() !== '' || form.attachment !== null))

function submit(): void {
  if (!canSubmit.value) {
    return
  }

  form.post(props.urls.reply, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: (response) => {
      // Inertia v3 carries flash data on the page object, not in its props.
      const flash = (response as { flash?: { error?: string | null } | null }).flash

      // Only the server's confirmation ends the draft; a refused or failed send keeps the text and the id for a retry.
      if (!flash?.error) {
        form.text = ''
        removeAttachment()
        form.request_id = newRequestId()
        scrollToBottom()
      }
    },
  })
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
    event.preventDefault()
    submit()
  }
}

// Operator actions.
const busy = ref(false)
const requestCallbacks = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) }

function takeOver(): void {
  router.post(props.urls.takeOver, {}, requestCallbacks)
}

function returnToBot(): void {
  router.post(props.urls.returnToBot, {}, requestCallbacks)
}

function toggleStatus(): void {
  router.put(props.urls.status, { status: props.conversation.status === 'open' ? 'closed' : 'open' }, requestCallbacks)
}
</script>

<template>
  <Head :title="conversation.contact" />

  <div class="flex w-full flex-col gap-4">
    <div class="flex flex-col gap-2">
      <Button as-child variant="ghost" size="sm" class="-ml-2 w-fit">
        <Link :href="urls.index">
          <ArrowLeft aria-hidden="true" />
          {{ t.back }}
        </Link>
      </Button>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 flex-col gap-1">
          <h1 class="font-display text-[22px] leading-tight font-semibold break-words">{{ conversation.contact }}</h1>
          <p class="text-muted-foreground text-sm">
            <span class="capitalize">{{ conversation.platform }}</span>
            · {{ interpolate(t.message_count, { count: conversation.messageCount }) }}
            · {{ ownerLine }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <StatusDot :tone="STATUS_TONES[conversation.status]">{{ t.statuses[conversation.status] }}</StatusDot>
          <LiveHint :transport="transport" :poll-ms="POLL_MS" :labels="t.live" />
          <Button v-if="can.takeOver" size="sm" :disabled="busy" @click="takeOver">{{ t.actions.take_over }}</Button>
          <Button v-if="can.returnToBot" variant="outline" size="sm" :disabled="busy" @click="returnToBot">{{ t.actions.return_to_bot }}</Button>
          <Button v-if="can.setStatus" variant="outline" size="sm" :disabled="busy" @click="toggleStatus">
            {{ conversation.status === 'open' ? t.actions.close : t.actions.reopen }}
          </Button>
        </div>
      </div>
    </div>

    <div class="bg-surface-muted flex flex-col rounded-xl border">
      <div ref="transcript" class="flex max-h-[60vh] min-h-64 flex-col gap-3 overflow-y-auto p-4">
        <div v-if="paging.hasMore" class="flex justify-center">
          <Button variant="outline" size="sm" @click="loadOlder">{{ t.load_older }}</Button>
        </div>

        <EmptyState v-if="messages.length === 0" :icon="MessageSquareOff" :title="t.empty_thread" class="my-auto" />

        <template v-for="group in groups" :key="group.key">
          <div class="text-muted-foreground flex justify-center text-xs">
            <span class="bg-muted rounded-full px-3 py-1">{{ dayLabel(group.date) }}</span>
          </div>

          <div v-for="message in group.messages" :key="message.id" :class="['flex flex-col gap-1', message.direction === 'outbound' ? 'items-end' : 'items-start']">
            <span class="text-muted-foreground flex items-center gap-1.5 text-xs">
              {{ message.author }}
              <Badge v-if="message.senderType === 'staff'" variant="info">{{ t.owner.staff }}</Badge>
            </span>
            <div :class="['max-w-[85%] rounded-xl px-3 py-2 text-sm sm:max-w-[70%]', bubbleClass(message)]">
              <p v-if="message.contentType !== 'text'" class="text-muted-foreground mb-1 text-xs">{{ t.content_types[message.contentType] ?? message.contentType }}</p>

              <p v-if="message.text" class="break-words whitespace-pre-wrap">{{ message.text }}</p>

              <div v-for="(item, index) in message.media" :key="index" class="mt-2">
                <a v-if="item.status === 'ready' && item.url && item.kind === 'image'" :href="item.url" target="_blank" rel="noopener">
                  <img :src="item.url" :alt="item.fileName ?? t.attachment" class="max-h-64 rounded-lg" loading="lazy" />
                </a>
                <a v-else-if="item.status === 'ready' && item.url" :href="item.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 underline">
                  <FileText class="size-4" aria-hidden="true" />
                  {{ item.fileName ?? t.attachment }}
                </a>
                <span v-else class="text-muted-foreground inline-flex items-center gap-1.5">
                  <FileText class="size-4" aria-hidden="true" />
                  {{ item.fileName ?? t.attachment }} · {{ item.status === 'failed' ? t.media.failed : t.media.pending }}
                </span>
              </div>

              <div v-if="message.buttons.length > 0" class="mt-2 flex flex-wrap gap-1.5">
                <Badge v-for="(label, index) in message.buttons" :key="index" variant="outline">{{ label }}</Badge>
              </div>

              <p class="text-muted-foreground mt-1 flex items-center justify-end gap-1.5 text-[11px] tabular-nums">
                <span>{{ timeLabel(message.createdAt, locale) }}</span>
                <span v-if="deliveryGlyph(message.delivery)" :title="t.delivery[message.delivery ?? ''] ?? message.delivery ?? undefined" :class="message.delivery === 'failed' ? 'text-destructive' : ''">{{ deliveryGlyph(message.delivery) }}</span>
              </p>
            </div>
          </div>
        </template>
      </div>

      <form v-if="can.compose" class="flex flex-col gap-2 border-t p-3" @submit.prevent="submit">
        <Textarea
          v-model="form.text"
          rows="3"
          :maxlength="textMax"
          :disabled="form.processing"
          :placeholder="t.composer.placeholder"
          :aria-label="t.composer.label"
          :aria-invalid="form.errors.text !== undefined"
          @keydown="onKeydown"
        />
        <p v-if="form.errors.text" role="alert" class="text-destructive text-[12.5px]">{{ form.errors.text }}</p>

        <div v-if="form.attachment" class="flex items-center gap-2 text-sm">
          <Paperclip class="size-4" aria-hidden="true" />
          <span class="truncate">{{ form.attachment.name }}</span>
          <Button type="button" variant="ghost" size="icon-sm" :aria-label="t.composer.remove" :disabled="form.processing" @click="removeAttachment">
            <X aria-hidden="true" />
          </Button>
        </div>
        <p v-if="attachmentError || form.errors.attachment" role="alert" class="text-destructive text-[12.5px]">{{ attachmentError ?? form.errors.attachment }}</p>

        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-3">
            <input ref="fileInput" type="file" class="sr-only" :accept="attachment.accept" :disabled="form.processing" tabindex="-1" aria-hidden="true" @change="onFileChosen" />
            <Button type="button" variant="outline" size="sm" :disabled="form.processing" @click="fileInput?.click()">
              <Paperclip aria-hidden="true" />
              {{ t.composer.attach }}
            </Button>
            <span class="text-muted-foreground hidden text-xs sm:inline">{{ t.composer.hint }}</span>
          </div>
          <Button type="submit" size="sm" :disabled="!canSubmit">
            <Send aria-hidden="true" />
            {{ form.processing ? t.composer.sending : t.actions.send }}
          </Button>
        </div>
      </form>

      <p v-else-if="can.reply" class="text-muted-foreground border-t p-3 text-sm">{{ t.composer.locked }}</p>
    </div>
  </div>
</template>
