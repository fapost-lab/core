<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import ContactGroupForm from './Form.vue'
import type { ContactGroupsPageProps } from './types'

defineProps<{
  group: { id: string; name: string; description: string | null }
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<ContactGroupsPageProps>().props.translations.console.contact_groups)
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <h1 class="font-display text-2xl font-semibold tracking-wide uppercase">{{ t.edit_title }}</h1>

    <ContactGroupForm :key="group.id" :initial="group" method="put" :submit-url="urls.submit" :cancel-url="urls.index" />
  </div>
</template>
