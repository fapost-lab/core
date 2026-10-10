<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <tr
    data-slot="table-row"
    :class="cn('hover:bg-surface-muted data-[state=selected]:bg-row-selected border-border-soft border-b transition-colors', props.class)"
  >
    <slot />
  </tr>
</template>
