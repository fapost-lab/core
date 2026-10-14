<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import SolutionMount from './SolutionMount.vue'
import type { ShellSlotName } from './extensions'
import { useExtensions } from './useExtensions'

/**
 * The shell elements Solutions declared for one slot of `AppShell`: `floating` in a fixed corner, `header` beside the
 * header's controls. They mount only once the page has rendered and the browser is idle, so their chunks never hold
 * up the first paint; the shell is a persistent layout, so this happens once per full load.
 */
const props = defineProps<{ place: ShellSlotName }>()

const { items, assistantId } = useExtensions('shell', props.place)
const ready = ref(false)

let cancel: (() => void) | null = null

onMounted(() => {
  if (typeof window.requestIdleCallback === 'function') {
    const handle = window.requestIdleCallback(() => (ready.value = true), { timeout: 2000 })
    cancel = () => window.cancelIdleCallback(handle)
  } else {
    const handle = window.setTimeout(() => (ready.value = true), 0)
    cancel = () => window.clearTimeout(handle)
  }
})

onBeforeUnmount(() => cancel?.())
</script>

<template>
  <div
    v-if="ready && items.length > 0"
    :class="place === 'floating' ? 'fixed right-4 bottom-4 z-30 flex flex-col items-end gap-2' : 'flex items-center gap-1'"
    :data-solution-slot="place"
  >
    <SolutionMount v-for="element in items" :key="element.id" :component="element.component" :assistant-id="assistantId" />
  </div>
</template>
