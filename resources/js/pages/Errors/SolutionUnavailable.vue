<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
/**
 * Rendered by the console's page resolver in place of a Solution page (`<id>::<Path>`) that is not in the map of
 * active Solutions or failed to load, so the operator keeps the shell instead of a blank screen. The cause is in the
 * browser console.
 */
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { PlugZap } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { EmptyState } from '@fapost/ui/components/empty-state'

const page = usePage<{ translations: { console: { solution_unavailable: { title: string; description: string; back: string } } } }>()
const t = computed(() => page.props.translations.console.solution_unavailable)

function goBack(): void {
  window.history.back()
}
</script>

<template>
  <Head :title="t.title" />
  <div class="flex flex-1 items-center justify-center p-10">
    <EmptyState :title="t.title" :description="t.description" :icon="PlugZap">
      <Button variant="outline" @click="goBack">{{ t.back }}</Button>
    </EmptyState>
  </div>
</template>
