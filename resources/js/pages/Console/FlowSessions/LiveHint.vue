<script setup lang="ts">
import { computed } from 'vue'
import { Radio, RefreshCw } from '@lucide/vue'
import { interpolate } from '@fapost/ui/shell'
import type { LiveTransport } from '@fapost/ui/lib/live-updates'
import type { LiveTranslations } from './types'

/** Says how the screen stays current: live through the websocket, or refreshed every few seconds. */
const props = defineProps<{
  transport: LiveTransport
  pollMs: number
  labels: LiveTranslations
}>()

const text = computed(() => (props.transport === 'echo' ? props.labels.live : interpolate(props.labels.polling, { seconds: Math.round(props.pollMs / 1000) })))
</script>

<template>
  <span class="text-muted-foreground inline-flex items-center gap-1.5 text-sm">
    <Radio v-if="transport === 'echo'" class="size-4" aria-hidden="true" />
    <RefreshCw v-else class="size-4" aria-hidden="true" />
    {{ text }}
  </span>
</template>
