<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft, ListOrdered } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import CopyButton from '../Contacts/CopyButton.vue'
import { LOG_STATUS_TONES, type FlowLogDetails, type FlowLogsPageProps, type KeyValue } from './types'

const props = defineProps<{
  log: FlowLogDetails
  stateChanges: KeyValue[]
  resolved: KeyValue[]
  error: KeyValue[]
  urls: { index: string; session?: string }
}>()

const page = usePage<FlowLogsPageProps>()
const t = computed(() => page.props.translations.console.flow_logs)

// The error first, then what the node changed and what it resolved; an empty section is not drawn.
const sections = computed(() =>
  [
    { key: 'error', title: t.value.view.error, entries: props.error, open: true },
    { key: 'stateChanges', title: t.value.view.state_changes, entries: props.stateChanges, open: false },
    { key: 'resolved', title: t.value.view.resolved, entries: props.resolved, open: false },
  ].filter((section) => section.entries.length > 0),
)
</script>

<template>
  <Head :title="`${log.nodeType} · ${log.nodeId}`" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-2">
      <Button as-child variant="ghost" size="sm" class="-ml-2 w-fit">
        <Link :href="urls.index">
          <ArrowLeft aria-hidden="true" />
          {{ t.back }}
        </Link>
      </Button>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="font-display text-[22px] leading-tight font-semibold break-all">
          {{ log.nodeType }} <span class="text-muted-foreground font-mono text-base font-normal">{{ log.nodeId }}</span>
        </h1>
        <Button v-if="urls.session" as-child variant="outline" size="sm">
          <Link :href="urls.session">
            <ListOrdered aria-hidden="true" />
            {{ t.view.open_session }}
          </Link>
        </Button>
      </div>
    </div>

    <Card>
      <CardHeader>
        <CardTitle>{{ t.view.entry }}</CardTitle>
      </CardHeader>
      <CardContent>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.created_at }}</dt>
            <dd :title="log.createdAt">{{ relativeTime(log.createdAt, page.props.locale) }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.status }}</dt>
            <dd><StatusDot :tone="LOG_STATUS_TONES[log.status] ?? 'neutral'">{{ t.statuses[log.status] ?? log.status }}</StatusDot></dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.session }}</dt>
            <dd class="flex items-center gap-1">
              <span class="truncate font-mono text-sm">{{ log.sessionId }}</span>
              <CopyButton :value="log.sessionId" />
            </dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.node_id }}</dt>
            <dd class="truncate font-mono text-sm">{{ log.nodeId }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.node_type }}</dt>
            <dd><Badge variant="neutral">{{ log.nodeType }}</Badge></dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.node_version }}</dt>
            <dd class="tabular-nums">{{ log.nodeVersion }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.source_handle }}</dt>
            <dd class="font-mono text-sm">{{ log.sourceHandle ?? t.view.empty_value }}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <Card v-for="section in sections" :key="section.key">
      <CardContent>
        <details :open="section.open">
          <summary class="cursor-pointer font-semibold select-none">{{ section.title }}</summary>
          <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
            <template v-for="entry in section.entries" :key="entry.key">
              <dt class="text-muted-foreground font-mono text-xs break-all">{{ entry.key }}</dt>
              <dd class="font-mono text-sm break-all whitespace-pre-wrap">{{ entry.value }}</dd>
            </template>
          </dl>
        </details>
      </CardContent>
    </Card>
  </div>
</template>
