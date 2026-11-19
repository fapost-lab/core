<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import CredentialForm from './CredentialForm.vue'
import type { CredentialTypeSchema, CredentialsPageProps, EditableCredential } from './types'

defineProps<{
  credential: EditableCredential
  types: CredentialTypeSchema[]
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<CredentialsPageProps>().props.translations.console.credentials)
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.edit_title }}</h1>

    <CredentialForm :key="credential.id" mode="edit" method="put" :types="types" :credential="credential" :submit-url="urls.submit" :cancel-url="urls.index" />
  </div>
</template>
