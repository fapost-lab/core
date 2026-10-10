<script setup lang="ts">
import { computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Send } from '@lucide/vue'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@fapost/ui/components/alert-dialog'
import ReachLine from './ReachLine.vue'
import { useReach } from './useReach'
import type { BroadcastRow, BroadcastsPageProps, SelectOption } from './types'

/**
 * The confirmation a draft is sent from, and the only way to send one. It shows what is about to go out (the name, the
 * base-language text, the audience and, counted fresh when it opens, how many contacts that is) and says it cannot be recalled. The reach is
 * advice: when it cannot be counted, sending stays possible and the server checks everything on its own.
 *
 * The send button is off while a send is on its way, and the dialog closes on the first click, so a second click has
 * nothing to land on. The revision that goes with the send is the one of the row the dialog was opened for.
 */
const props = defineProps<{
  row: BroadcastRow | null
  segments: SelectOption[]
  reachUrl: string
  sending: boolean
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  confirm: []
}>()

const page = usePage<BroadcastsPageProps>()
const t = computed(() => page.props.translations.console.broadcasts)
const common = computed(() => page.props.translations.console.form)

const reach = useReach(
  () => props.reachUrl,
  () => ({ targetType: props.row?.target ?? 'all', targetTags: props.row?.targetTags ?? [], targetSegmentId: props.row?.targetSegmentId ?? null }),
  { auto: false },
)

watch(open, (isOpen) => {
  if (isOpen && props.row) {
    reach.refresh()
  }
})

const audience = computed(() => {
  const row = props.row

  if (!row) {
    return ''
  }

  if (row.target === 'tags') {
    return `${t.value.targets.tags}: ${row.targetTags.join(', ')}`
  }

  if (row.target === 'segment') {
    const name = props.segments.find((segment) => segment.value === row.targetSegmentId)?.label

    return name ? `${t.value.targets.segment}: ${name}` : t.value.targets.segment
  }

  return t.value.targets.all
})
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogContent>
      <div class="flex gap-3.5">
        <span class="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-full">
          <Send class="size-5" aria-hidden="true" />
        </span>
        <AlertDialogHeader class="gap-1.5">
          <AlertDialogTitle>{{ t.send.title }}</AlertDialogTitle>
          <AlertDialogDescription>{{ t.send.description }}</AlertDialogDescription>
        </AlertDialogHeader>
      </div>

      <dl class="bg-surface-muted grid gap-x-4 gap-y-2 rounded-lg border p-3.5 text-sm sm:grid-cols-[auto_1fr]">
        <dt class="text-muted-foreground">{{ t.send.name }}</dt>
        <dd class="font-medium break-words">{{ row?.name }}</dd>
        <dt class="text-muted-foreground">{{ t.send.audience }}</dt>
        <dd class="break-words">{{ audience }}</dd>
        <dt class="text-muted-foreground">{{ t.send.message }}</dt>
        <dd class="break-words whitespace-pre-line">{{ row?.excerpt || '—' }}</dd>
        <dt class="text-muted-foreground">{{ t.fields.reach }}</dt>
        <dd><ReachLine :state="reach.state.value" :count="reach.count.value" :message="reach.message.value" /></dd>
      </dl>

      <AlertDialogFooter>
        <AlertDialogCancel>{{ common.cancel }}</AlertDialogCancel>
        <AlertDialogAction :disabled="sending" @click="emit('confirm')">
          {{ sending ? t.send.sending : t.send.confirm }}
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
