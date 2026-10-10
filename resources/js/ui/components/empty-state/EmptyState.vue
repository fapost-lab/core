<script setup lang="ts">
import type { Component, HTMLAttributes } from "vue"
import { Inbox } from "@lucide/vue"
import { cn } from "@fapost/ui/lib/utils"

/**
 * What a list or a section shows when it has nothing yet: an icon tile, a title, a hint, and the action that fills it
 * (the default slot). Inside a table it sits in the empty row; on its own it is a dashed card.
 */
const props = defineProps<{
  title: string
  description?: string
  icon?: Component
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div data-slot="empty-state" :class="cn('flex flex-col items-center gap-2.5 text-center', props.class)">
    <span class="bg-primary-soft text-primary-hover flex size-11 items-center justify-center rounded-xl">
      <component :is="icon ?? Inbox" class="size-[22px]" aria-hidden="true" />
    </span>
    <span class="font-semibold">{{ title }}</span>
    <span v-if="description" class="text-muted-foreground max-w-xs text-[13px]">{{ description }}</span>
    <div v-if="$slots.default" class="mt-1"><slot /></div>
  </div>
</template>
