<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import CredentialForm from './CredentialForm.vue'
import type { CredentialTypeSchema, CredentialsPageProps } from './types'

defineProps<{
  types: CredentialTypeSchema[]
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<CredentialsPageProps>().props.translations.console.credentials)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>

    <CredentialForm mode="create" method="post" :types="types" :submit-url="urls.submit" :cancel-url="urls.index" />
  </div>
</template>
