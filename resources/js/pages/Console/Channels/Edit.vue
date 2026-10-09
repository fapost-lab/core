<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import ChannelForm from './ChannelForm.vue'
import type { ChannelsPageProps, EditableChannel, MaxConnections, SelectOption } from './types'
import WebhookStatus from './WebhookStatus.vue'

const props = defineProps<{
  channel: EditableChannel
  types: SelectOption[]
  telegramUpdates: SelectOption[]
  maxConnections: MaxConnections
  urls: { index: string; submit: string }
}>()

const page = usePage<ChannelsPageProps>()
const t = computed(() => page.props.translations.console.channels)

function registerAgain(): void {
  router.post(props.channel.registerWebhookUrl, {}, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.edit_title }}</h1>

    <div v-if="channel.webhook === 'failed'" class="border-destructive/40 flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center" role="status">
      <div class="flex flex-1 flex-col gap-1">
        <div class="flex flex-wrap items-center gap-2">
          <WebhookStatus />
          <span v-if="channel.webhookAt" class="text-muted-foreground text-xs" :title="channel.webhookAt">
            {{ interpolate(t.webhook.failed_at, { time: relativeTime(channel.webhookAt, page.props.locale) }) }}
          </span>
        </div>
        <p class="text-sm">{{ t.webhook.failed_hint }}</p>
      </div>
      <Button type="button" variant="outline" @click="registerAgain">{{ t.webhook.register_again }}</Button>
    </div>

    <ChannelForm
      :key="channel.id"
      mode="edit"
      method="put"
      :submit-url="urls.submit"
      :cancel-url="urls.index"
      :types="types"
      :telegram-updates="telegramUpdates"
      :max-connections="maxConnections"
      :channel="channel"
    />
  </div>
</template>
