<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft, ChevronLeft, ChevronRight } from '@lucide/vue'
import { StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@fapost/ui/components/sheet'
import type { LiveChannel } from '@console/lib/live-updates'
import { useLiveUpdates } from '@console/lib/useLiveUpdates'
import { interpolate } from '@console/shell'
import { xsrfHeaders } from '@shared/http'
import { fallbackTypeLabel } from '@builder/utils/nodeAvailability'
import LiveHint from './LiveHint.vue'
import { drawRunGraph, loopsOf, RUN_STATUS_TONES, type DrawnNode, type PassSelection, type RunGraph } from '@console/run-graph/runGraph'
import { STATUS_TONES, type FlowSessionStatus, type FlowSessionsPageProps } from './types'

/** Vue Flow and ELK load with the canvas, only on this page: every console page is in the main bundle. */
const RunGraphCanvas = defineAsyncComponent(() => import('@console/run-graph/RunGraphCanvas.vue'))

interface Visit {
  event: string
  at: string | null
  status: 'done' | 'waiting' | 'failed' | 'running'
  handle: string | null
  next: string | null
  durationMs: number | null
  iterations: Record<string, number>
  errorClass: string | null
}

const props = defineProps<{
  session: { id: string; status: FlowSessionStatus; flowName: string | null; isLive: boolean }
  graph: RunGraph
  live: LiveChannel | null
  urls: { session: string; visits: string }
}>()

const POLL_MS = 15_000

const page = usePage<FlowSessionsPageProps>()
const t = computed(() => page.props.translations.console.flow_sessions)
const g = computed(() => t.value.graph)

const { transport } = useLiveUpdates({
  live: () => props.live,
  only: ['session', 'graph'],
  pollMs: POLL_MS,
  active: () => props.session.isLive,
})

/** The pass each loop shows; a loop not chosen shows its last pass, so a live run follows its newest one. */
const selection = ref<PassSelection>({})
const loops = computed(() => loopsOf(props.graph.flow).filter((loop) => loop.count > 0))

const drawn = computed(() => drawRunGraph(props.graph, selection.value))
const selectedNode = ref<DrawnNode | null>(null)

function step(key: string, count: number, delta: number): void {
  const loop = drawn.value.boxes.find((box): box is DrawnNode => box.kind === 'node' && box.loop?.key === key)
  const current = loop?.loop?.index ?? count - 1

  selection.value = { ...selection.value, [key]: Math.min(Math.max(current + delta, 0), count - 1) }
}

function choose(key: string, value: string): void {
  selection.value = { ...selection.value, [key]: Number(value) }
}

function shownIndex(key: string, count: number): number {
  const loop = drawn.value.boxes.find((box): box is DrawnNode => box.kind === 'node' && box.loop?.key === key)

  return loop?.loop?.index ?? count - 1
}

// The node panel: the node's newest visits, statuses only, loaded when it opens.
const panelOpen = ref(false)
const visits = ref<Visit[] | null>(null)
const visitsFailed = ref(false)
let visitsRequest = 0

async function openNode(box: DrawnNode): Promise<void> {
  selectedNode.value = box
  panelOpen.value = true
  visits.value = null
  visitsFailed.value = false

  const request = ++visitsRequest
  const query = new URLSearchParams({ session: box.sessionId, node: box.node.id })

  try {
    const response = await fetch(`${props.urls.visits}?${query.toString()}`, {
      headers: { Accept: 'application/json', ...xsrfHeaders() },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      throw new Error(String(response.status))
    }

    const body = (await response.json()) as { visits: Visit[] }

    if (request === visitsRequest) {
      visits.value = body.visits
    }
  } catch {
    if (request === visitsRequest) {
      visitsFailed.value = true
    }
  }
}

watch(panelOpen, (open) => {
  if (!open) {
    selectedNode.value = null
  }
})

function iterationsText(iterations: Record<string, number>): string {
  return Object.values(iterations).map((pass) => String(pass)).join(' · ')
}
</script>

<template>
  <Head :title="g.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-2">
      <Button as-child variant="ghost" size="sm" class="-ml-2 w-fit">
        <Link :href="urls.session">
          <ArrowLeft aria-hidden="true" />
          {{ g.back }}
        </Link>
      </Button>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex min-w-0 flex-col gap-1">
          <h1 class="font-display text-[22px] leading-tight font-semibold">{{ g.title }}</h1>
          <p class="text-muted-foreground text-sm">{{ g.description }}</p>
          <p class="text-muted-foreground truncate text-sm">
            {{ session.flowName ?? t.view.empty_value }} · <span class="font-mono">{{ session.id }}</span>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <StatusDot :tone="STATUS_TONES[session.status]">{{ t.statuses[session.status] }}</StatusDot>
          <LiveHint v-if="session.isLive" :transport="transport" :poll-ms="POLL_MS" :labels="t.live" />
        </div>
      </div>
    </div>

    <p v-if="graph.truncated" class="text-muted-foreground text-sm">{{ interpolate(g.truncated, { count: graph.limit }) }}</p>

    <Card v-if="loops.length">
      <CardHeader>
        <CardTitle>{{ g.loop.title }}</CardTitle>
      </CardHeader>
      <CardContent class="flex flex-col gap-3">
        <div v-for="loop in loops" :key="loop.key" class="flex flex-wrap items-center gap-3">
          <span class="min-w-40 truncate text-sm font-medium">{{ loop.node?.label ?? loop.node?.typeLabel ?? fallbackTypeLabel(loop.node?.type ?? 'loop') }}</span>
          <Button variant="outline" size="icon-sm" :aria-label="g.loop.previous" :disabled="shownIndex(loop.key, loop.count) === 0" @click="step(loop.key, loop.count, -1)">
            <ChevronLeft aria-hidden="true" />
          </Button>
          <input
            type="range"
            class="accent-primary w-48"
            min="0"
            :max="loop.count - 1"
            :value="shownIndex(loop.key, loop.count)"
            :aria-label="g.loop.title"
            @input="choose(loop.key, ($event.target as HTMLInputElement).value)"
          />
          <Button variant="outline" size="icon-sm" :aria-label="g.loop.next" :disabled="shownIndex(loop.key, loop.count) === loop.count - 1" @click="step(loop.key, loop.count, 1)">
            <ChevronRight aria-hidden="true" />
          </Button>
          <span class="text-muted-foreground text-sm tabular-nums">{{ interpolate(g.loop.pass, { pass: shownIndex(loop.key, loop.count) + 1, count: loop.count }) }}</span>
        </div>
      </CardContent>
    </Card>

    <Card class="overflow-hidden p-0">
      <div v-if="graph.flow.nodes.length" class="bg-muted/20 h-[70vh] w-full">
        <RunGraphCanvas :graph="graph" :selection="selection" :labels="g" :selected-id="selectedNode?.id ?? null" @select="openNode" />
      </div>
      <p v-else class="text-muted-foreground p-6 text-sm">{{ g.empty }}</p>
    </Card>

    <div class="text-muted-foreground flex flex-wrap items-center gap-3 text-xs">
      <StatusDot v-for="(tone, status) in RUN_STATUS_TONES" :key="status" :tone="tone">{{ g.statuses[status] }}</StatusDot>
    </div>

    <Sheet v-model:open="panelOpen">
      <SheetContent class="flex flex-col gap-4 overflow-y-auto">
        <SheetHeader>
          <SheetTitle>{{ selectedNode?.node.label ?? selectedNode?.node.typeLabel ?? g.node.title }}</SheetTitle>
          <SheetDescription>
            <span class="font-mono">{{ selectedNode?.node.id }}</span> · {{ g.node.description }}
          </SheetDescription>
        </SheetHeader>
        <div class="px-4 pb-4">
          <p v-if="visitsFailed" class="text-destructive text-sm">{{ g.node.failed }}</p>
          <p v-else-if="visits === null" class="text-muted-foreground text-sm">{{ g.node.loading }}</p>
          <p v-else-if="visits.length === 0" class="text-muted-foreground text-sm">{{ g.node.empty }}</p>
          <ol v-else class="flex flex-col divide-y">
            <li v-for="(visit, index) in visits" :key="index" class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
              <div class="flex flex-wrap items-center gap-2 text-sm">
                <StatusDot :tone="RUN_STATUS_TONES[visit.status]">{{ g.node.events[visit.event] ?? visit.event }}</StatusDot>
                <span v-if="visit.at" class="text-muted-foreground ml-auto text-xs" :title="visit.at">{{ visit.at }}</span>
              </div>
              <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-xs">
                <template v-if="visit.handle">
                  <dt class="text-muted-foreground">{{ g.node.handle }}</dt>
                  <dd class="truncate font-mono">{{ visit.handle }}</dd>
                </template>
                <template v-if="visit.next">
                  <dt class="text-muted-foreground">{{ g.node.next }}</dt>
                  <dd class="truncate font-mono">{{ visit.next }}</dd>
                </template>
                <template v-if="visit.durationMs !== null">
                  <dt class="text-muted-foreground">{{ g.node.duration }}</dt>
                  <dd class="tabular-nums">{{ interpolate(g.node.duration_value, { value: visit.durationMs }) }}</dd>
                </template>
                <template v-if="Object.keys(visit.iterations).length">
                  <dt class="text-muted-foreground">{{ g.node.iterations }}</dt>
                  <dd class="tabular-nums">{{ iterationsText(visit.iterations) }}</dd>
                </template>
                <template v-if="visit.errorClass">
                  <dt class="text-muted-foreground">{{ g.node.error }}</dt>
                  <dd class="truncate font-mono">{{ visit.errorClass }}</dd>
                </template>
              </dl>
            </li>
          </ol>
        </div>
      </SheetContent>
    </Sheet>
  </div>
</template>
