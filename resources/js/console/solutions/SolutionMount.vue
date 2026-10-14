<script setup lang="ts">
import { computed, defineAsyncComponent, onErrorCaptured, ref } from 'vue'
import { loadSolutionComponent } from './loader'

/**
 * Mounts a component a Solution declared for one of Core's screens (`<id>::<Path>`), loaded from its prebuilt bundle.
 * Whatever goes wrong — the name is not in the map, the module does not load within the loader's timeout, the
 * component throws while rendering — hides this element, reports to the browser console, and leaves the screen
 * working (ADR solution-console-ui). The component receives no more than the ids below.
 */
const props = defineProps<{
  component: string
  recordId?: string | null
  assistantId?: string | null
}>()

const emit = defineEmits<{ failed: [error: unknown] }>()

const failed = ref(false)

// One wrapper per name: the loader caches nothing itself, the module system does.
const Loaded = defineAsyncComponent(() => loadSolutionComponent(props.component))

const passed = computed(() => {
  const ids: { recordId?: string; assistantId?: string } = {}

  if (props.recordId) {
    ids.recordId = props.recordId
  }

  if (props.assistantId) {
    ids.assistantId = props.assistantId
  }

  return ids
})

onErrorCaptured((error) => {
  if (!failed.value) {
    console.error(`[solutions] ${props.component} is hidden:`, error)
    failed.value = true
    emit('failed', error)
  }

  // Stops here: a Solution's failure never reaches the page's or the app's error handler.
  return false
})
</script>

<template>
  <div v-if="!failed" :data-solution-component="component">
    <Loaded v-bind="passed" />
  </div>
</template>
