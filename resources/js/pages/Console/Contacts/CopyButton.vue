<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Check, Copy } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import type { ContactsPageProps } from './types'

const props = defineProps<{
  value: string
}>()

const page = usePage<ContactsPageProps>()
const t = computed(() => page.props.translations.console.contacts)

const copied = ref(false)

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // The text is on the page for the user to select by hand.
  }
}
</script>

<template>
  <Button type="button" variant="ghost" size="icon-sm" :aria-label="copied ? t.copied : t.copy" :title="copied ? t.copied : t.copy" @click="copy">
    <Check v-if="copied" aria-hidden="true" />
    <Copy v-else aria-hidden="true" />
  </Button>
</template>
