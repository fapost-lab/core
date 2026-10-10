<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AssistantForm from './Form.vue'
import type { AssistantsPageProps, LanguageOption } from './types'

defineProps<{
  languages: LanguageOption[]
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<AssistantsPageProps>().props.translations.console.assistants)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>

    <AssistantForm :initial="{ name: '', defaultLanguage: 'en', isActive: true }" :languages="languages" method="post" :submit-url="urls.submit" :cancel-url="urls.index" />
  </div>
</template>
