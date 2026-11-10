<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
/**
 * Rendered by the console's page resolver in place of a Solution page (`<id>::<Path>`) that is not in the map of
 * active Solutions or failed to load, so the operator keeps the shell instead of a blank screen; the cause is in the
 * browser console. Rendered by the server too, with `reason: 'update_required'` and status 503, on every page of an
 * active Solution whose interface no longer fits this platform (`EnsureSolutionActive`): then it says an update is needed.
 */
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { PlugZap, RefreshCw } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { EmptyState } from '@fapost/ui/components/empty-state'

interface UnavailableTranslations {
  title: string
  description: string
  back: string
  update_required: { title: string; description: string }
}

const props = defineProps<{ reason?: 'update_required' }>()

const page = usePage<{ translations: { console: { solution_unavailable: UnavailableTranslations } } }>()
const t = computed(() => page.props.translations.console.solution_unavailable)
const text = computed(() => (props.reason === 'update_required' ? t.value.update_required : t.value))

function goBack(): void {
  window.history.back()
}
</script>

<template>
  <Head :title="text.title" />
  <div class="flex flex-1 items-center justify-center p-10" :data-test="reason === 'update_required' ? 'solution-update-required' : 'solution-unavailable'">
    <EmptyState :title="text.title" :description="text.description" :icon="reason === 'update_required' ? RefreshCw : PlugZap">
      <Button variant="outline" @click="goBack">{{ t.back }}</Button>
    </EmptyState>
  </div>
</template>
