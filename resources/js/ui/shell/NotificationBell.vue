<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useHttp } from '@inertiajs/vue3'
import { Bell, CheckCheck, ExternalLink } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { StatusDot } from '@fapost/ui/components/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@fapost/ui/components/dropdown-menu'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from './nav'
import { NOTIFICATION_POLL_MS, READ_BEFORE_LEAVING_MS, allMarkedRead, badgeText, markedRead, settleWithin, type ShellNotification } from './notifications'
import type { NotificationsTranslations, ShellNotifications } from './types'

/**
 * The shell's notification bell, in both panels: the unread count from the shared `notifications` prop (kept current by
 * every visit and by polling the count while the tab is visible), and on opening, the latest notifications of the
 * signed-in user. Choosing one marks it read and opens its link, if it has one. Text is shown as text, never as HTML.
 */
const props = defineProps<{
  notifications: ShellNotifications
  locale: string
  labels: NotificationsTranslations
}>()

type State = 'idle' | 'loading' | 'ready' | 'error'

const unread = ref(props.notifications.unread)
const items = ref<ShellNotification[]>([])
const state = ref<State>('idle')

const list = useHttp<Record<string, never>, { unread: number; items: ShellNotification[] }>({})
const counter = useHttp<Record<string, never>, { unread: number }>({})
const writer = useHttp<Record<string, never>, { unread: number }>({})

let timer: ReturnType<typeof setInterval> | null = null

// Every Inertia visit brings a fresh count.
watch(
  () => props.notifications.unread,
  (value) => {
    unread.value = value
  },
)

const badge = computed(() => badgeText(unread.value))
const label = computed(() =>
  unread.value > 0 ? `${props.labels.label} (${interpolate(props.labels.unread, { count: String(unread.value) })})` : props.labels.label,
)

function load(): void {
  state.value = items.value.length === 0 ? 'loading' : state.value

  list
    .get(props.notifications.indexUrl)
    .then((response) => {
      items.value = response.items
      unread.value = response.unread
      state.value = 'ready'
    })
    .catch(() => {
      state.value = 'error'
    })
}

function refreshCount(): void {
  if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
    return
  }

  counter
    .get(props.notifications.countUrl)
    .then((response) => {
      unread.value = response.unread
    })
    .catch(() => {
      // The next poll or visit tries again.
    })
}

function onOpenChange(open: boolean): void {
  if (open) {
    load()
  }
}

async function choose(item: ShellNotification): Promise<void> {
  let saved: Promise<unknown> = Promise.resolve()

  if (!item.read) {
    items.value = markedRead(items.value, item.id)
    unread.value = Math.max(0, unread.value - 1)
    saved = writer
      .post(item.readUrl)
      .then((response) => {
        unread.value = response.unread
      })
      .catch(() => {
        // Still unread on the server: the next count shows it.
      })
  }

  if (item.action) {
    // Leaving the page would abort the request: wait for it, but never longer than a moment.
    await settleWithin(saved, READ_BEFORE_LEAVING_MS)
    window.location.assign(item.action.url)
  }
}

function markAll(): void {
  items.value = allMarkedRead(items.value)
  unread.value = 0
  writer
    .post(props.notifications.readAllUrl)
    .then((response) => {
      unread.value = response.unread
    })
    .catch(() => refreshCount())
}

function onVisibility(): void {
  if (document.visibilityState === 'visible') {
    refreshCount()
  }
}

onMounted(() => {
  timer = setInterval(refreshCount, NOTIFICATION_POLL_MS)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  if (timer !== null) {
    clearInterval(timer)
  }

  document.removeEventListener('visibilitychange', onVisibility)
  list.cancel()
  counter.cancel()
})
</script>

<template>
  <DropdownMenu @update:open="onOpenChange">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="icon-sm" class="relative" :aria-label="label" data-test="notification-bell">
        <Bell aria-hidden="true" />
        <span
          v-if="badge"
          class="bg-primary text-primary-foreground absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold"
          aria-hidden="true"
          data-test="notification-badge"
        >
          {{ badge }}
        </span>
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-[360px] max-w-[calc(100vw-2rem)] p-0">
      <div class="flex items-center justify-between gap-2 px-3 py-2">
        <DropdownMenuLabel class="p-0 text-sm font-semibold">{{ props.labels.title }}</DropdownMenuLabel>
        <Button
          v-if="unread > 0"
          variant="ghost"
          size="sm"
          class="h-7 px-2 text-xs"
          data-test="notification-mark-all"
          @click="markAll"
        >
          <CheckCheck aria-hidden="true" />
          {{ props.labels.mark_all }}
        </Button>
      </div>
      <DropdownMenuSeparator class="m-0" />

      <div class="max-h-[420px] overflow-y-auto p-1">
        <p v-if="state === 'loading'" class="text-muted-foreground px-3 py-6 text-center text-sm">{{ props.labels.loading }}</p>
        <p v-else-if="state === 'error'" class="text-muted-foreground px-3 py-6 text-center text-sm">{{ props.labels.error }}</p>
        <p v-else-if="state === 'ready' && items.length === 0" class="text-muted-foreground px-3 py-6 text-center text-sm">
          {{ props.labels.empty }}
        </p>
        <DropdownMenuItem
          v-for="item in items"
          v-else
          :key="item.id"
          class="items-start gap-3 px-2 py-2"
          :data-read="item.read ? '' : undefined"
          data-test="notification-item"
          @select="choose(item)"
        >
          <StatusDot :tone="item.tone" class="mt-1.5" />
          <span class="min-w-0 flex-1">
            <span :class="['block text-sm', item.read ? 'text-muted-foreground' : 'text-foreground font-medium']">
              {{ item.title || props.labels.untitled }}
            </span>
            <span v-if="item.body" class="text-muted-foreground mt-0.5 line-clamp-3 block text-xs">{{ item.body }}</span>
            <span class="text-faint-foreground mt-1 flex items-center gap-2 text-[11px]">
              <time v-if="item.createdAt" :datetime="item.createdAt">{{ relativeTime(item.createdAt, props.locale) }}</time>
              <span v-if="item.action" class="inline-flex items-center gap-1">
                <ExternalLink class="size-3" aria-hidden="true" />
                {{ item.action.label || props.labels.open }}
              </span>
            </span>
          </span>
          <span v-if="!item.read" class="bg-primary mt-1.5 size-2 shrink-0 rounded-full" data-test="notification-unread">
            <span class="sr-only">{{ props.labels.unread_item }}</span>
          </span>
        </DropdownMenuItem>
      </div>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
