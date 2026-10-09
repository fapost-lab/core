<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <th
    data-slot="table-head"
    :class="cn('text-muted-foreground font-display px-2 py-2.5 text-left align-middle text-[13px] font-semibold tracking-[0.03em] whitespace-nowrap uppercase [&:has([role=checkbox])]:pr-0 *:[[role=checkbox]]:translate-y-0.5', props.class)"
  >
    <slot />
  </th>
</template>
