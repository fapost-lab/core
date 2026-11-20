<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Check, Copy, TriangleAlert } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { interpolate } from '@console/shell'
import type { ApiTokensPageProps } from './types'

/**
 * Shows a token that was just issued or rotated, once. The plain value arrives as the `apiToken` flash of the redirect
 * (flash data is not kept in the browser's history) and is held here only while the dialog is open: closing it drops
 * the value, and nothing else on the page ever has it.
 */
const page = usePage<ApiTokensPageProps>()
const t = computed(() => page.props.translations.console.api_tokens)

const revealed = ref<{ name: string; plain: string } | null>(null)
const open = ref(false)
const copied = ref(false)

watch(
  () => (page.flash as { apiToken?: { name: string; plain: string } } | undefined)?.apiToken,
  (token) => {
    if (token?.plain) {
      revealed.value = { name: token.name, plain: token.plain }
      copied.value = false
      open.value = true
    }
  },
  { immediate: true },
)

watch(open, (isOpen) => {
  if (!isOpen) {
    revealed.value = null
  }
})

async function copy(): Promise<void> {
  if (!revealed.value) {
    return
  }

  try {
    await navigator.clipboard.writeText(revealed.value.plain)
    copied.value = true
  } catch {
    copied.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg" @interact-outside.prevent>
      <DialogHeader>
        <DialogTitle>{{ t.reveal.title }}</DialogTitle>
        <DialogDescription>{{ interpolate(t.reveal.description, { name: revealed?.name ?? '' }) }}</DialogDescription>
      </DialogHeader>

      <div role="note" class="border-warning/40 bg-warning/10 flex items-start gap-2 rounded-md border px-3 py-2 text-[13px]">
        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <span>{{ t.reveal.warning }}</span>
      </div>

      <code data-testid="api-token-plain" class="bg-surface-muted block rounded-md border px-3 py-2 font-mono text-[13px] break-all select-all">{{ revealed?.plain }}</code>

      <DialogFooter>
        <Button type="button" variant="outline" @click="copy">
          <component :is="copied ? Check : Copy" aria-hidden="true" />
          {{ copied ? t.reveal.copied : t.reveal.copy }}
        </Button>
        <Button type="button" @click="open = false">{{ t.reveal.done }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
