<script setup lang="ts">
// Temporary: used by the usage page and the dashboard card. Moves to @fapost/ui as `Meter` in the follow-up task `kit-meter-component`.
import { computed } from 'vue'
import { cn } from '@fapost/ui/lib/utils'
import type { BarTone } from './usage'

/**
 * A progress bar for a limit, drawn with the kit's tokens. It is a meter (a measured value in a known range), so a
 * screen reader reads "Assistants, 80% used". It moves into the kit when a second page needs it.
 */
const props = defineProps<{
  percent: number
  tone: BarTone
  label: string
}>()

const value = computed(() => Math.min(100, Math.max(0, Math.round(props.percent))))
const fill = computed(() => ({ primary: 'bg-primary', warning: 'bg-warning-dot', danger: 'bg-danger-dot' })[props.tone])
</script>

<template>
  <div
    role="meter"
    class="bg-muted h-2 w-full overflow-hidden rounded-full"
    :aria-label="label"
    :aria-valuenow="value"
    aria-valuemin="0"
    aria-valuemax="100"
    data-test="usage-bar"
    :data-tone="tone"
  >
    <div :class="cn('h-full rounded-full transition-[width]', fill)" :style="{ width: `${value}%` }" />
  </div>
</template>
