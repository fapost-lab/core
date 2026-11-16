<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
/**
 * Rendered by the console's page resolver in place of a Solution page (`<id>::<Path>`) that is not in the map of
 * active Solutions or failed to load, so the operator keeps the shell instead of a blank screen; the cause is in the
 * browser console. Rendered by the server too, with status 503 and a `reason` (`EnsureSolutionActive`): `update_required` on every page of an
 * active Solution whose interface no longer fits this platform, saying an update is needed; `preparing` while its data is
 * being set up for the tenant, saying to try again in a minute.
 */
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { Hourglass, PlugZap, RefreshCw } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { EmptyState } from '@fapost/ui/components/empty-state'

interface UnavailableTranslations {
  title: string
  description: string
  back: string
  update_required: { title: string; description: string }
  preparing: { title: string; description: string }
}

const props = defineProps<{ reason?: 'update_required' | 'preparing' }>()

const page = usePage<{ translations: { console: { solution_unavailable: UnavailableTranslations } } }>()
const t = computed(() => page.props.translations.console.solution_unavailable)
const text = computed(() => (props.reason === 'update_required' || props.reason === 'preparing' ? t.value[props.reason] : t.value))
const dataTest = computed(() =>
  props.reason === 'update_required' ? 'solution-update-required' : props.reason === 'preparing' ? 'solution-preparing' : 'solution-unavailable',
)
const icon = computed(() => (props.reason === 'update_required' ? RefreshCw : props.reason === 'preparing' ? Hourglass : PlugZap))

function goBack(): void {
  window.history.back()
}
</script>

<template>
  <Head :title="text.title" />
  <div class="flex flex-1 items-center justify-center p-10" :data-test="dataTest">
    <EmptyState :title="text.title" :description="text.description" :icon="icon">
      <Button variant="outline" @click="goBack">{{ t.back }}</Button>
    </EmptyState>
  </div>
</template>
