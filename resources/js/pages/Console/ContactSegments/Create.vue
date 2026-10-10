<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import ContactSegmentForm from './Form.vue'
import type { ContactSegmentsPageProps, SegmentOptions, SegmentSchema } from './types'

defineProps<{
  schema: SegmentSchema
  options: SegmentOptions
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<ContactSegmentsPageProps>().props.translations.console.contact_segments)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>

    <ContactSegmentForm
      :initial="{ name: '', match: schema.match[0]?.value ?? '', conditions: [] }"
      method="post"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :schema="schema"
      :options="options"
    />
  </div>
</template>
