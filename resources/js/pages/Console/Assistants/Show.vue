<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft, Pencil, SquareArrowOutUpRight } from '@lucide/vue'
import { StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardHeader, CardTitle } from '@fapost/ui/components/card'
import type { TableDefaults, TableMeta, TableState } from '@fapost/ui/components/data-table'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import ChannelsTable from '../Channels/ChannelsTable.vue'
import type { ChannelRow } from '../Channels/types'
import type { AssistantsPageProps } from './types'

defineProps<{
  assistant: {
    id: string
    name: string
    isActive: boolean
    defaultLanguage: string
    languageLabel: string
    createdAt: string | null
    updatedAt: string | null
  }
  /** The assistant's channels; null when the user may not see channels. */
  channels: {
    table: { rows: ChannelRow[]; meta: TableMeta; state: TableState; defaults: TableDefaults & { perPageOptions: number[] } }
    can: { update: boolean; delete: boolean; rotate: boolean }
  } | null
  can: { update: boolean }
  urls: { index: string; show: string; edit: string; console: string }
}>()

const page = usePage<AssistantsPageProps>()
const t = computed(() => page.props.translations.console.assistants)
const common = computed(() => page.props.translations.console.form)
</script>

<template>
  <Head :title="assistant.name" />

  <div class="flex w-full flex-col gap-5">
    <Link :href="urls.index" class="text-muted-foreground inline-flex w-fit items-center gap-1 text-sm underline-offset-4 hover:underline">
      <ArrowLeft class="size-4" aria-hidden="true" />
      {{ t.back }}
    </Link>

    <div class="flex flex-wrap items-center gap-3">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ assistant.name }}</h1>
      <StatusDot :tone="assistant.isActive ? 'success' : 'neutral'">{{ assistant.isActive ? t.status.active : t.status.inactive }}</StatusDot>
      <div class="ml-auto flex items-center gap-2">
        <Button as-child variant="outline">
          <Link :href="urls.console">
            <SquareArrowOutUpRight aria-hidden="true" />
            {{ t.manage }}
          </Link>
        </Button>
        <Button v-if="can.update" as-child>
          <Link :href="urls.edit">
            <Pencil aria-hidden="true" />
            {{ common.edit }}
          </Link>
        </Button>
      </div>
    </div>

    <Card>
      <CardHeader>
        <CardTitle>{{ t.details }}</CardTitle>
      </CardHeader>
      <CardContent>
        <dl class="grid gap-4 sm:grid-cols-3">
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.fields.default_language }}</dt>
            <dd>{{ assistant.languageLabel }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.fields.created }}</dt>
            <dd :title="assistant.createdAt ?? undefined">{{ assistant.createdAt ? relativeTime(assistant.createdAt, page.props.locale) : '—' }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.fields.updated }}</dt>
            <dd :title="assistant.updatedAt ?? undefined">{{ assistant.updatedAt ? relativeTime(assistant.updatedAt, page.props.locale) : '—' }}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <section v-if="channels" class="flex flex-col gap-3" aria-labelledby="assistant-channels">
      <div class="flex flex-col gap-1">
        <h2 id="assistant-channels" class="font-display text-xl font-semibold">{{ t.channels }}</h2>
        <p class="text-muted-foreground text-sm">{{ t.channels_hint }}</p>
      </div>

      <ChannelsTable :table="channels.table" :can="channels.can" :url="urls.show" />
    </section>
  </div>
</template>
