<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ArrowLeft, FileText, RotateCcw, Workflow } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import type { LiveChannel } from '@console/lib/live-updates'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { useLiveUpdates } from '@console/lib/useLiveUpdates'
import { interpolate } from '@console/shell'
import { templateNodeLabel } from './templateNode'
import CopyButton from '../Contacts/CopyButton.vue'
import LiveHint from './LiveHint.vue'
import {
  END_STATUS_TONES,
  STATUS_TONES,
  type FlowSessionDetails,
  type FlowSessionsPageProps,
  type HistoryEntry,
  type KeyValue,
  type RerunOffer,
  type RerunRow,
  type RerunSource,
} from './types'

const props = defineProps<{
  session: FlowSessionDetails
  state: KeyValue[]
  history: HistoryEntry[] | null
  rerun: RerunOffer | null
  reruns: RerunRow[]
  rerunOf: RerunSource | null
  live: LiveChannel | null
  urls: { index: string; graph: string; parent?: string; logs?: string }
}>()

const POLL_MS = 30_000
/** How long the page keeps reloading after it queued a re-run, waiting for the new run to show up. */
const AWAIT_RERUN_MS = 120_000
/** Matches FlowSessionInspector::HISTORY_LIMIT. */
const HISTORY_LIMIT = 200

const page = usePage<FlowSessionsPageProps>()
const t = computed(() => page.props.translations.console.flow_sessions)
const form = computed(() => page.props.translations.console.form)

const rerunOpen = ref(false)
const rerunning = ref(false)
/** Set for a while after a re-run was queued: the page keeps reloading until the new run is listed. */
const awaitingRerun = ref(false)
let awaitTimer: ReturnType<typeof setTimeout> | undefined

function stopAwaiting(): void {
  awaitingRerun.value = false
  clearTimeout(awaitTimer)
}

// The new run is listed: nothing more to wait for.
watch(() => props.reruns.length, (count, previous) => {
  if (count > previous) {
    stopAwaiting()
  }
})

onBeforeUnmount(stopAwaiting)

/** What the confirmation says: the warning that everything is sent again, and the version the re-run runs. */
const rerunDescription = computed(() => {
  const offer = props.rerun
  if (!offer || offer.targetVersion === null) {
    return ''
  }

  const description = interpolate(t.value.rerun.confirm_description, { version: offer.targetVersion })

  return offer.targetVersion === props.session.flowVersion
    ? description
    : `${description} ${interpolate(t.value.rerun.version_changed, { source: props.session.flowVersion })}`
})

function rerunNow(): void {
  if (!props.rerun?.allowed || rerunning.value) {
    return
  }

  router.post(props.rerun.url, { attempt: props.rerun.attempt }, {
    preserveScroll: true,
    // Kept, so the page goes on waiting for the run it queued.
    preserveState: true,
    onStart: () => (rerunning.value = true),
    onFinish: () => (rerunning.value = false),
    onSuccess: () => {
      // Refused, the offer says why and nothing is on its way.
      if (!props.rerun?.allowed) {
        return
      }

      stopAwaiting()
      awaitingRerun.value = true
      awaitTimer = setTimeout(stopAwaiting, AWAIT_RERUN_MS)
    },
  })
}

// A finished session changes no more, so its page stops listening — unless a re-run of it is on its way or still live.
const { transport } = useLiveUpdates({
  live: () => props.live,
  only: ['session', 'state', 'history', 'rerun', 'reruns'],
  pollMs: POLL_MS,
  active: () => props.session.isLive || awaitingRerun.value || props.reruns.some((row) => row.isRunning),
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
          <Button as-child variant="outline" size="sm">
            <Link :href="urls.graph">
              <Workflow aria-hidden="true" />
              {{ t.view.graph }}
            </Link>
          </Button>
          <Button v-if="urls.logs" as-child variant="outline" size="sm">
            <Link :href="urls.logs">
              <FileText aria-hidden="true" />
              {{ t.view.logs }}
            </Link>
          </Button>
          <Button
            v-if="rerun"
            variant="outline"
            size="sm"
            data-test="rerun"
            :disabled="!rerun.allowed || rerunning"
            :title="rerun.refusal ? t.rerun.refusals[rerun.refusal] : undefined"
            @click="rerunOpen = true"
          >
            <RotateCcw aria-hidden="true" />
            {{ t.rerun.action }}
          </Button>
        </div>
      </div>
      <p v-if="rerun?.refusal" class="text-muted-foreground text-sm" data-test="rerun-refusal">{{ t.rerun.refusals[rerun.refusal] }}</p>
      <div v-if="rerunOf" class="flex items-center gap-1 text-sm" data-test="rerun-of">
        <span class="text-muted-foreground">{{ t.rerun.rerun_of }}</span>
        <Link :href="rerunOf.url" class="font-mono hover:underline">{{ rerunOf.id }}</Link>
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
            <dd v-if="session.currentNodeOrigin" class="text-muted-foreground truncate text-xs">{{ templateNodeLabel(t.template_node, session.currentNodeOrigin) }}</dd>
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

    <Card v-if="reruns.length" data-test="reruns">
      <CardHeader>
        <CardTitle>{{ t.rerun.reruns }}</CardTitle>
        <CardDescription>{{ t.rerun.reruns_description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <ul class="flex flex-col divide-y">
          <li v-for="row in reruns" :key="row.id" class="flex flex-wrap items-center gap-3 py-2 first:pt-0 last:pb-0">
            <Link :href="row.url" class="font-mono text-sm hover:underline">{{ row.id }}</Link>
            <StatusDot :tone="STATUS_TONES[row.status]">{{ t.statuses[row.status] }}</StatusDot>
            <span v-if="row.createdAt" class="text-muted-foreground ml-auto text-xs" :title="row.createdAt">{{ when(row.createdAt) }}</span>
          </li>
        </ul>
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
              <span v-if="entry.nodeOrigin" class="text-muted-foreground text-xs">{{ templateNodeLabel(t.template_node, entry.nodeOrigin) }}</span>
              <span v-if="entry.path" class="text-muted-foreground font-mono text-xs">{{ entry.path }}</span>
              <span v-if="entry.createdAt" class="text-muted-foreground ml-auto text-xs" :title="entry.createdAt">{{ when(entry.createdAt) }}</span>
            </div>
            <p v-if="entry.payload" class="font-mono text-xs break-all whitespace-pre-wrap">{{ entry.payload }}</p>
          </li>
        </ol>
        <p v-else class="text-muted-foreground text-sm">{{ t.view.history_empty }}</p>
      </CardContent>
    </Card>

    <ConfirmDialog
      v-if="rerun?.allowed"
      v-model:open="rerunOpen"
      :title="t.rerun.confirm_title"
      :description="rerunDescription"
      :confirm-label="t.rerun.confirm"
      :cancel-label="form.cancel"
      @confirm="rerunNow"
    />
  </div>
</template>
