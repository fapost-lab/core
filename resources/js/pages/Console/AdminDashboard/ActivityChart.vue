<script setup lang="ts">
import { computed } from 'vue'
import { dayLabel, isQuiet, percentOf, scaleMax } from './activity'
import type { ActivityDay } from './types'

/**
 * Node executions per day as stacked bars (executed below, failed on top), drawn with the kit's tokens: the console
 * ships no chart library. The same numbers are in a visually hidden table for screen readers.
 */
const props = defineProps<{
  days: ActivityDay[]
  locale: string
  labels: { executed: string; failed: string; day: string; empty: string }
}>()

const max = computed(() => scaleMax(props.days))
const quiet = computed(() => isQuiet(props.days))
const bars = computed(() =>
  props.days.map((day) => ({
    ...day,
    label: dayLabel(day.date, props.locale),
    executedHeight: percentOf(day.executed, max.value),
    failedHeight: percentOf(day.failed, max.value),
  })),
)
</script>

<template>
  <div class="flex flex-col gap-3" data-test="activity-chart">
    <div class="text-muted-foreground flex flex-wrap items-center gap-4 text-[13px]" aria-hidden="true">
      <span class="flex items-center gap-1.5"><span class="bg-chart-1 size-2.5 rounded-sm" />{{ labels.executed }}</span>
      <span class="flex items-center gap-1.5"><span class="bg-danger-dot size-2.5 rounded-sm" />{{ labels.failed }}</span>
    </div>

    <p v-if="quiet" class="text-muted-foreground py-6 text-center text-sm">{{ labels.empty }}</p>

    <div v-else class="flex flex-col gap-1" aria-hidden="true">
      <div class="flex items-stretch gap-2">
        <div class="text-faint-foreground flex h-40 w-8 shrink-0 flex-col justify-between text-right text-[11px] tabular-nums">
          <span>{{ max }}</span>
          <span>0</span>
        </div>
        <div class="border-border-soft flex h-40 flex-1 items-end gap-1 border-b sm:gap-1.5">
          <div
            v-for="bar in bars"
            :key="bar.date"
            class="flex h-full min-w-0 flex-1 flex-col justify-end"
            :title="`${bar.label}: ${labels.executed} ${bar.executed}, ${labels.failed} ${bar.failed}`"
          >
            <div class="bg-danger-dot rounded-t-sm" :style="{ height: `${bar.failedHeight}%` }" />
            <div :class="['bg-chart-1', bar.failed === 0 ? 'rounded-t-sm' : '']" :style="{ height: `${bar.executedHeight}%` }" />
          </div>
        </div>
      </div>
      <div class="flex gap-2">
        <div class="w-8 shrink-0" />
        <div class="text-faint-foreground flex flex-1 gap-1 text-[11px] sm:gap-1.5">
          <span v-for="(bar, index) in bars" :key="bar.date" class="min-w-0 flex-1 truncate text-center">
            {{ index % 2 === 0 ? bar.label : '' }}
          </span>
        </div>
      </div>
    </div>

    <table class="sr-only">
      <thead>
        <tr>
          <th scope="col">{{ labels.day }}</th>
          <th scope="col">{{ labels.executed }}</th>
          <th scope="col">{{ labels.failed }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="bar in bars" :key="bar.date">
          <th scope="row">{{ bar.label }}</th>
          <td>{{ bar.executed }}</td>
          <td>{{ bar.failed }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
