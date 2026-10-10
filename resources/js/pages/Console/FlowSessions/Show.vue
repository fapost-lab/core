<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft, FileText } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import type { LiveChannel } from '@fapost/ui/lib/live-updates'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { useLiveUpdates } from '@fapost/ui/lib/useLiveUpdates'
import { interpolate } from '@fapost/ui/shell'
import CopyButton from '../Contacts/CopyButton.vue'
import LiveHint from './LiveHint.vue'
import { END_STATUS_TONES, STATUS_TONES, type FlowSessionDetails, type FlowSessionsPageProps, type HistoryEntry, type KeyValue } from './types'

const props = defineProps<{
  session: FlowSessionDetails
  state: KeyValue[]
  history: HistoryEntry[] | null
  live: LiveChannel | null
  urls: { index: string; parent?: string; logs?: string }
}>()

const POLL_MS = 30_000
/** Matches FlowSessionInspector::HISTORY_LIMIT. */
const HISTORY_LIMIT = 200

const page = usePage<FlowSessionsPageProps>()
const t = computed(() => page.props.translations.console.flow_sessions)

// A finished session changes no more, so its page stops listening.
const { transport } = useLiveUpdates({
  live: () => props.live,
  only: ['session', 'state', 'history'],
  pollMs: POLL_MS,
  active: () => props.session.isLive,
})

function when(value: string | null): string | null {
  return value ? relativeTime(value, page.props.locale) : null
}
</script>

<template>
  <Head :title="session.id" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-2">
      <Button as-child variant="ghost" size="sm" class="-ml-2 w-fit">
        <Link :href="urls.index">
          <ArrowLeft aria-hidden="true" />
          {{ t.back }}
        </Link>
      </Button>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="font-display flex items-center gap-1 text-[22px] leading-tight font-semibold break-all">
          <span class="font-mono">{{ session.id }}</span>
          <CopyButton :value="session.id" />
        </h1>
        <div class="flex flex-wrap items-center gap-3">
          <LiveHint v-if="session.isLive" :transport="transport" :poll-ms="POLL_MS" :labels="t.live" />
          <Button v-if="urls.logs" as-child variant="outline" size="sm">
            <Link :href="urls.logs">
              <FileText aria-hidden="true" />
              {{ t.view.logs }}
            </Link>
          </Button>
        </div>
      </div>
    </div>

    <Card>
      <CardHeader>
        <CardTitle>{{ t.view.identity }}</CardTitle>
      </CardHeader>
      <CardContent>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.status }}</dt>
            <dd><StatusDot :tone="STATUS_TONES[session.status]">{{ t.statuses[session.status] }}</StatusDot></dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.end_status }}</dt>
            <dd>
              <Badge v-if="session.endStatus" :variant="END_STATUS_TONES[session.endStatus] ?? 'neutral'">{{ t.end_statuses[session.endStatus] ?? session.endStatus }}</Badge>
              <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
            </dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.current_node }}</dt>
            <dd class="truncate font-mono text-sm">{{ session.currentNodeId ?? t.view.empty_value }}</dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.flow }}</dt>
            <dd class="truncate">{{ session.flowName ?? t.view.empty_value }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.flow_version }}</dt>
            <dd class="tabular-nums">{{ session.flowVersion }}</dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.contact }}</dt>
            <dd class="truncate font-mono text-sm">{{ session.contactExternalId ?? t.view.empty_value }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.created_at }}</dt>
            <dd :title="session.createdAt ?? undefined">{{ when(session.createdAt) ?? t.view.empty_value }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.updated_at }}</dt>
            <dd :title="session.updatedAt ?? undefined">{{ when(session.updatedAt) ?? t.view.empty_value }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.version }}</dt>
            <dd class="tabular-nums">{{ session.version }}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <Card v-if="session.parentId">
      <CardHeader>
        <CardTitle>{{ t.view.parent }}</CardTitle>
      </CardHeader>
      <CardContent>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.parent_session }}</dt>
            <dd class="flex items-center gap-1">
              <Link v-if="urls.parent" :href="urls.parent" class="truncate font-mono text-sm hover:underline">{{ session.parentId }}</Link>
              <CopyButton :value="session.parentId" />
            </dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.parent_resume_node }}</dt>
            <dd class="truncate font-mono text-sm">{{ session.parentResumeNodeId ?? t.view.empty_value }}</dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.expires_at }}</dt>
            <dd :title="session.expiresAt ?? undefined">{{ when(session.expiresAt) ?? t.view.empty_value }}</dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle>{{ t.view.state }}</CardTitle>
      </CardHeader>
      <CardContent>
        <dl v-if="state.length" class="grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
          <template v-for="entry in state" :key="entry.key">
            <dt class="text-muted-foreground font-mono text-xs break-all">{{ entry.key }}</dt>
            <dd class="font-mono text-sm break-all whitespace-pre-wrap">{{ entry.value }}</dd>
          </template>
        </dl>
        <p v-else class="text-muted-foreground text-sm">{{ t.view.state_empty }}</p>
      </CardContent>
    </Card>

    <Card v-if="history !== null">
      <CardHeader>
        <CardTitle>{{ t.view.history }}</CardTitle>
        <CardDescription v-if="history.length">{{ interpolate(t.view.history_note, { count: HISTORY_LIMIT }) }}</CardDescription>
      </CardHeader>
      <CardContent>
        <ol v-if="history.length" class="flex flex-col divide-y">
          <li v-for="entry in history" :key="entry.id" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <Badge variant="neutral">{{ entry.event }}</Badge>
              <span class="font-mono text-xs">{{ entry.nodeId }}</span>
              <span v-if="entry.path" class="text-muted-foreground font-mono text-xs">{{ entry.path }}</span>
              <span v-if="entry.createdAt" class="text-muted-foreground ml-auto text-xs" :title="entry.createdAt">{{ when(entry.createdAt) }}</span>
            </div>
            <p v-if="entry.payload" class="font-mono text-xs break-all whitespace-pre-wrap">{{ entry.payload }}</p>
          </li>
        </ol>
        <p v-else class="text-muted-foreground text-sm">{{ t.view.history_empty }}</p>
      </CardContent>
    </Card>
  </div>
</template>
