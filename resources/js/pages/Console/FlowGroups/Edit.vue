<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import FlowGroupForm from './Form.vue'
import type { FlowGroupsPageProps } from './types'

defineProps<{
  group: { id: string; name: string }
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<FlowGroupsPageProps>().props.translations.console.flow_groups)
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.edit_title }}</h1>

    <FlowGroupForm :key="group.id" :initial="group" method="put" :submit-url="urls.submit" :cancel-url="urls.index" />
  </div>
</template>
