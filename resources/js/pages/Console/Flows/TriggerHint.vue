<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Clock, Code, Terminal, Webhook, Zap } from '@lucide/vue'
import type { Component } from 'vue'
import type { FlowsPageProps, FlowTrigger } from './types'

/** What starts a flow, under its name in the list: an icon for the kind of trigger and the summary the server wrote. */
const props = defineProps<{
  trigger: FlowTrigger
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
</script>

<template>
  <div class="text-muted-foreground mt-0.5 flex items-center gap-1.5 text-xs">
    <component :is="icon" class="size-3.5 shrink-0" role="img" :aria-label="typeLabel" />
    <span class="truncate">{{ trigger.text }}</span>
  </div>
</template>
