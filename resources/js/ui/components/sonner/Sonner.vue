<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { CheckIcon, InfoIcon, Loader2Icon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { Toaster as Sonner } from "vue-sonner"
import "vue-sonner/style.css"
import { computed } from "vue"
import { cn } from '@fapost/ui/lib/utils'

const props = defineProps<ToasterProps>()

// The toast is a dark pill in both themes: its description, close button and border follow the toast tokens.
const toastOptions = computed(() => ({
  ...props.toastOptions,
  classes: {
    description: 'text-toast-muted-foreground!',
    closeButton: 'bg-toast! text-toast-foreground! border-toast-border!',
    ...props.toastOptions?.classes,
  },
}))
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--toast)',
      '--normal-text': 'var(--toast-foreground)',
      '--normal-border': 'var(--toast-border)',
      '--border-radius': 'calc(var(--radius) + 2px)',
    }"
    v-bind="{ ...props, toastOptions }"
  >
    <template #success-icon>
      <span class="bg-primary text-primary-foreground flex size-[22px] items-center justify-center rounded-full">
        <CheckIcon class="size-3.5" stroke-width="2.5" />
      </span>
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <span class="bg-destructive text-destructive-foreground flex size-[22px] items-center justify-center rounded-full">
        <XIcon class="size-3.5" stroke-width="2.5" />
      </span>
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
