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
  groups: FlowGroupOption[]
  can: { createGroup: boolean }
  urls: { index: string; submit: string; storeGroup: string }
}>()

const t = computed(() => usePage<FlowsPageProps>().props.translations.console.flows)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>

    <FlowForm
      :initial="{ name: '', flowGroupId: null, description: null, isPublic: true, loggingEnabled: false }"
      method="post"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :groups="groups"
      :can-create-group="can.createGroup"
      :store-group-url="urls.storeGroup"
      :submit-label="t.create_and_open"
    />
  </div>
</template>
