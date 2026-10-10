<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import BroadcastForm from './Form.vue'
import type { BroadcastFormState, BroadcastLanguage, BroadcastsPageProps, SelectOption } from './types'

defineProps<{
  form: BroadcastFormState
  languages: BroadcastLanguage[]
  options: { tags: string[]; segments: SelectOption[] }
  urls: { index: string; submit: string; reach: string }
}>()

const t = computed(() => usePage<BroadcastsPageProps>().props.translations.console.broadcasts)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>

    <BroadcastForm
      :initial="form"
      :languages="languages"
      :options="options"
      method="post"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :reach-url="urls.reach"
    />
  </div>
</template>
