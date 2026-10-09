<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@fapost/ui/lib/utils"

/** A status in a table cell: a coloured dot and a plain label (active, unsubscribed, blocked). */
const props = withDefaults(
  defineProps<{
    tone?: "success" | "info" | "warning" | "danger" | "neutral"
    class?: HTMLAttributes["class"]
  }>(),
  { tone: "neutral" },
)

const dots = {
  success: "bg-success-dot",
  info: "bg-info-dot",
  warning: "bg-warning-dot",
  danger: "bg-danger-dot",
  neutral: "bg-neutral-dot",
} as const
</script>

<template>
  <span data-slot="status-dot" :class="cn('text-neutral-foreground inline-flex items-center gap-1.5 text-[12.5px]', props.class)">
    <span :class="cn('size-2 shrink-0 rounded-full', dots[tone])" aria-hidden="true" />
    <slot />
  </span>
</template>
