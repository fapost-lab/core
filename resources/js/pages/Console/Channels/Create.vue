<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import ChannelForm from './ChannelForm.vue'
import type { ChannelsPageProps, MaxConnections, SelectOption } from './types'

defineProps<{
  types: SelectOption[]
  telegramUpdates: SelectOption[]
  maxConnections: MaxConnections
  urls: { index: string; submit: string }
}>()

const t = computed(() => usePage<ChannelsPageProps>().props.translations.console.channels)
</script>

<template>
  <Head :title="t.create_title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <h1 class="font-display text-2xl font-semibold tracking-wide uppercase">{{ t.create_title }}</h1>

    <ChannelForm
      mode="create"
      method="post"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :types="types"
      :telegram-updates="telegramUpdates"
      :max-connections="maxConnections"
    />
  </div>
</template>
