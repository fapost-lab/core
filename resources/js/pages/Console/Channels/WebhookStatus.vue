<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import type { ChannelsPageProps } from './types'

/**
 * The webhook badge of a channel whose last registration the provider refused. The one place that picks the badge
 * variant: when the kit gets a soft warning variant, change it here.
 */
const t = computed(() => usePage<ChannelsPageProps>().props.translations.console.channels.webhook)
</script>

<template>
  <Badge variant="destructive">
    <TriangleAlert aria-hidden="true" />
    {{ t.failed }}
  </Badge>
</template>
