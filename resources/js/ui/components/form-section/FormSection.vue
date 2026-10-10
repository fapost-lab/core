<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@fapost/ui/lib/utils"

/**
 * A group of related form fields on a card: what the group is for on the left (title and a line of help), the
 * fields on the right. On a narrow screen the two stack. `danger` is the frame of a destructive group.
 */
const props = defineProps<{
  title: string
  description?: string
  danger?: boolean
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <section
    data-slot="form-section"
    :class="
      cn(
        'bg-card grid gap-6 rounded-xl border p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]',
        danger && 'border-danger-border',
        props.class,
      )
    "
  >
    <div>
      <h2 :class="cn('font-display text-[17px] leading-snug font-semibold', danger && 'text-danger-foreground')">{{ title }}</h2>
      <p v-if="description" class="text-muted-foreground mt-1.5 text-[13px]">{{ description }}</p>
    </div>
    <div class="flex min-w-0 flex-col gap-[18px]">
      <slot />
    </div>
  </section>
</template>
