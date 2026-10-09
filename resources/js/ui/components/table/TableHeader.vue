<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <thead
    data-slot="table-header"
    :class="cn('bg-surface-muted [&_tr]:border-border [&_tr]:border-b', props.class)"
  >
    <slot />
  </thead>
</template>
