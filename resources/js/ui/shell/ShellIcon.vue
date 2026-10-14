<script setup lang="ts">
import { computed, shallowRef, watch, type Component } from 'vue'
import { Circle } from '@lucide/vue'
import { KNOWN_ICON_NAMES, iconFor, loadIcon } from './icons'

/**
 * An icon the server names (lucide, kebab case). Core's own render at once; any other lucide icon — a Solution's —
 * comes from the lazy catalogue (`loadIcon`), and until it arrives, or when lucide has no such name, the slot shows a
 * plain circle (`fallback`) or nothing.
 */
const props = withDefaults(defineProps<{ name: string; fallback?: boolean }>(), { fallback: true })

const loaded = shallowRef<Component | null>(null)

watch(
  () => props.name,
  (name) => {
    loaded.value = null

    if (!KNOWN_ICON_NAMES.includes(name)) {
      void loadIcon(name).then((icon) => {
        if (props.name === name) {
          loaded.value = icon
        }
      })
    }
  },
  { immediate: true },
)

const icon = computed<Component | null>(() => (KNOWN_ICON_NAMES.includes(props.name) ? iconFor(props.name) : (loaded.value ?? (props.fallback ? Circle : null))))
</script>

<template>
  <component :is="icon" v-if="icon" :data-icon="name" />
</template>
