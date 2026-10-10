<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Badge } from '@fapost/ui/components/badge'
import { interpolate } from '@fapost/ui/shell'
import type { ReachState } from './useReach'
import type { BroadcastsPageProps } from './types'

/**
 * The reach of an audience as a line: the number of contacts that would receive the message, "counting…" while the
 * server works, a plain "could not count" when it failed (sending stays possible; the server checks on its own), and a
 * dash before the audience is complete. Zero is a warning: a send to nobody does nothing.
 */
const props = defineProps<{
  state: ReachState
  count: number | null
}>()

const t = computed(() => usePage<BroadcastsPageProps>().props.translations.console.broadcasts.reach)

const text = computed(() => {
  if (props.state !== 'ready' || props.count === null) {
    return null
  }

  if (props.count === 0) {
    return t.value.none
  }

  return props.count === 1 ? t.value.one : interpolate(t.value.many, { count: props.count })
})
</script>

<template>
  <div class="flex flex-col gap-1" aria-live="polite">
    <span v-if="state === 'loading'" class="text-muted-foreground text-sm">{{ t.loading }}</span>
    <span v-else-if="state === 'error'" class="text-muted-foreground text-sm">{{ t.unavailable }}</span>
    <span v-else-if="text === null" class="text-muted-foreground text-sm">—</span>
    <Badge v-else :variant="count === 0 ? 'warning' : 'success'" class="w-fit">{{ text }}</Badge>
  </div>
</template>
