<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Clock, Code, Terminal, Webhook, Zap } from '@lucide/vue'
import { interpolate } from '@console/shell'
import type { Component } from 'vue'
import type { FlowsPageProps, FlowTrigger } from './types'

/** What starts a flow, under its name in the list: an icon for the kind of trigger and the summary the server wrote. */
const props = defineProps<{
  trigger: FlowTrigger
  /**
   * How many other live triggers the flow has: a `+N` badge after the hint, apart from the hint's own ` +N`
   * for keywords beyond the first ones, and named in full by its title.
   */
  moreTriggers?: number
}>()

const icons: Record<string, Component> = {
  message: Terminal,
  schedule: Clock,
  webhook: Webhook,
  event: Zap,
  api: Code,
}

const t = computed(() => usePage<FlowsPageProps>().props.translations.console.flows)
const icon = computed(() => icons[props.trigger.type] ?? Zap)
const typeLabel = computed(() => t.value.trigger_types[props.trigger.type] ?? props.trigger.type)
const moreLabel = computed(() => interpolate(t.value.more_triggers, { count: props.moreTriggers ?? 0 }))
</script>

<template>
  <div class="text-muted-foreground mt-0.5 flex items-center gap-1.5 text-xs">
    <component :is="icon" class="size-3.5 shrink-0" role="img" :aria-label="typeLabel" />
    <span class="truncate">{{ trigger.text }}</span>
    <span
      v-if="(moreTriggers ?? 0) > 0"
      class="border-border shrink-0 rounded border px-1 leading-4"
      :title="moreLabel"
      :aria-label="moreLabel"
      data-testid="more-triggers"
    >+{{ moreTriggers }}</span>
  </div>
</template>
