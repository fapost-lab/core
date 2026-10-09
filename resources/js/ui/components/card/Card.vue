<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-4 rounded-xl border py-6',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
