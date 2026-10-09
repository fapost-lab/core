<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <h3
    data-slot="card-title"
    :class="cn('font-display text-[17px] leading-snug font-semibold', props.class)"
  >
    <slot />
  </h3>
</template>
