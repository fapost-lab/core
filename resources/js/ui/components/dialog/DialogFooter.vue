<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { DialogClose } from "reka-ui"
import { useKitMessages } from '@fapost/ui/lib/messages'
import { cn } from '@fapost/ui/lib/utils'
import { Button } from '@fapost/ui/components/button'

const props = withDefaults(defineProps<{
  class?: HTMLAttributes["class"]
  showCloseButton?: boolean
}>(), {
  showCloseButton: false,
})

const messages = useKitMessages()
</script>

<template>
  <div
    data-slot="dialog-footer"
    :class="cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', props.class)"
  >
    <slot />
    <DialogClose v-if="showCloseButton" as-child>
      <Button variant="outline">
        {{ messages.close }}
      </Button>
    </DialogClose>
  </div>
</template>
