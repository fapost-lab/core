<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import FlowForm from './Form.vue'
import type { FlowGroupOption, FlowsPageProps } from './types'

defineProps<{
  flow: { id: string; name: string; flowGroupId: string | null; description: string | null; isPublic: boolean; loggingEnabled: boolean }
  groups: FlowGroupOption[]
  can: { createGroup: boolean }
  urls: { index: string; submit: string; storeGroup: string }
}>()

const t = computed(() => usePage<FlowsPageProps>().props.translations.console.flows)
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <h1 class="font-display text-2xl font-semibold tracking-wide uppercase">{{ t.edit_title }}</h1>

    <FlowForm
      :key="flow.id"
      :initial="flow"
      method="put"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :groups="groups"
      :can-create-group="can.createGroup"
      :store-group-url="urls.storeGroup"
    />
  </div>
</template>
