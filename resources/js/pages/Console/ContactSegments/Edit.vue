<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import ContactSegmentForm from './Form.vue'
import type { ContactSegmentsPageProps, SegmentFields, SegmentOptions, SegmentSchema } from './types'

defineProps<{
  segment: SegmentFields & { id: string }
  size: number | null
  countedAt: string | null
  schema: SegmentSchema
  options: SegmentOptions
  urls: { index: string; submit: string }
}>()

const page = usePage<ContactSegmentsPageProps>()
const t = computed(() => page.props.translations.console.contact_segments)
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-2xl font-semibold tracking-wide uppercase">{{ t.edit_title }}</h1>
      <!-- Read-only: a recount is made from the list. -->
      <p class="text-muted-foreground text-sm">
        <template v-if="size !== null && countedAt">
          {{ t.current_size }}: {{ size }} · {{ interpolate(t.counted_at, { time: relativeTime(countedAt, page.props.locale) }) }}
        </template>
        <template v-else>{{ t.never_counted }}</template>
      </p>
    </div>

    <ContactSegmentForm
      :key="segment.id"
      :initial="segment"
      method="put"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :schema="schema"
      :options="options"
    />
  </div>
</template>
