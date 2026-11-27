<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Blocks } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@fapost/ui/components/table'
import { formatUtc, interpolate } from '@console/shell'
import type { NodeUsagePageData, NodeUsagePageProps } from './types'

/**
 * Which node types and actions the tenant's flows use, as `NodeUsageController` reads them through the same service as
 * `flow:node-usage`: in how many active flows and published versions, and — for whoever may view the flow log — how
 * often they ran over the chosen period. The period stays in the address (`?period`, the parameter the server names).
 */
const props = defineProps<NodeUsagePageData>()

const page = usePage<NodeUsagePageProps>()
const t = computed(() => page.props.translations.console.node_usage)
const number = computed(() => new Intl.NumberFormat(page.props.locale))
const loading = ref(false)

function choose(value: unknown): void {
  const period = String(value)
  const query = Object.fromEntries(new URLSearchParams(window.location.search))

  if (period === props.default) {
    delete query[props.param]
  } else {
    query[props.param] = period
  }

  loading.value = true
  router.get(window.location.pathname, query, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onFinish: () => {
      loading.value = false
    },
  })
}

function count(value: number | null): string {
  return value === null ? t.value.never : number.value.format(value)
}

function lastRun(value: string | null): string {
  return value === null ? t.value.never : formatUtc(value, page.props.locale)
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
        <p class="text-muted-foreground text-sm">{{ t.description }}</p>
      </div>

      <Select v-if="runs" :model-value="period" :disabled="loading" @update:model-value="choose">
        <SelectTrigger size="sm" class="w-44" :aria-label="t.period" data-test="node-usage-period">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in periods" :key="option" :value="option">{{ t.periods[option] }}</SelectItem>
        </SelectContent>
      </Select>
    </div>

    <div class="text-muted-foreground flex flex-col gap-1 text-[13px]">
      <p data-test="node-usage-window">{{ runs ? t.window : t.runs_hidden }}</p>
      <p v-if="unused === null" data-test="node-usage-scoped">{{ t.scoped }}</p>
    </div>

    <Card data-test="node-usage-nodes">
      <CardHeader>
        <CardTitle class="font-display">{{ t.nodes.title }}</CardTitle>
        <CardDescription>{{ t.nodes.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <EmptyState v-if="nodes.length === 0" :icon="Blocks" :title="t.nodes.empty" class="py-8" data-test="node-usage-nodes-empty" />

        <Table v-else>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t.columns.node }}</TableHead>
              <TableHead class="text-right" :title="t.hints.active_flows">{{ t.columns.active_flows }}</TableHead>
              <TableHead class="text-right" :title="t.hints.active_nodes">{{ t.columns.active_nodes }}</TableHead>
              <TableHead class="text-right" :title="t.hints.versions">{{ t.columns.versions }}</TableHead>
              <template v-if="runs">
                <TableHead class="text-right">{{ t.columns.executions }}</TableHead>
                <TableHead class="text-right">{{ t.columns.failures }}</TableHead>
                <TableHead class="text-right">{{ t.columns.last_run }}</TableHead>
              </template>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="row in nodes" :key="row.key" :data-test="`node-${row.key}`">
              <TableCell>
                <div class="flex flex-col gap-1">
                  <span class="font-medium">{{ row.label ?? row.type }}</span>
                  <div class="flex flex-wrap items-center gap-1.5">
                    <code class="text-muted-foreground text-xs">{{ row.key }}</code>
                    <Badge v-if="row.solution" variant="info">{{ interpolate(t.solution, { id: row.solution }) }}</Badge>
                    <Badge v-if="row.orphaned" variant="danger" data-test="node-orphaned">{{ t.orphaned }}</Badge>
                    <Badge v-else-if="!row.registered" variant="warning" data-test="node-missing">{{ t.missing }}</Badge>
                  </div>
                </div>
              </TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(row.activeFlows) }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(row.activeNodes) }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(row.versions) }}</TableCell>
              <template v-if="runs">
                <TableCell class="text-right tabular-nums">{{ count(row.executions) }}</TableCell>
                <TableCell :class="['text-right tabular-nums', (row.failures ?? 0) > 0 ? 'text-danger-foreground' : '']">{{ count(row.failures) }}</TableCell>
                <TableCell class="text-muted-foreground text-right whitespace-nowrap">{{ lastRun(row.lastRunAt) }}</TableCell>
              </template>
            </TableRow>
          </TableBody>
        </Table>
      </CardContent>
    </Card>

    <Card data-test="node-usage-actions">
      <CardHeader>
        <CardTitle class="font-display">{{ t.actions.title }}</CardTitle>
        <CardDescription>{{ t.actions.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <EmptyState v-if="actions.length === 0" :icon="Blocks" :title="t.actions.empty" class="py-8" data-test="node-usage-actions-empty" />

        <Table v-else>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t.columns.action }}</TableHead>
              <TableHead class="text-right" :title="t.hints.active_flows">{{ t.columns.active_flows }}</TableHead>
              <TableHead class="text-right" :title="t.hints.versions">{{ t.columns.versions }}</TableHead>
              <template v-if="runs">
                <TableHead class="text-right">{{ t.columns.executions }}</TableHead>
                <TableHead class="text-right">{{ t.columns.failures }}</TableHead>
                <TableHead class="text-right">{{ t.columns.last_run }}</TableHead>
              </template>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="action in actions" :key="action.id" :data-test="`action-${action.id}`">
              <TableCell>
                <div class="flex flex-wrap items-center gap-1.5">
                  <code class="font-medium">{{ action.id }}</code>
                  <Badge v-if="action.solution" variant="info">{{ interpolate(t.solution, { id: action.solution }) }}</Badge>
                  <Badge v-else-if="action.registered" variant="neutral">{{ t.core }}</Badge>
                  <Badge v-if="!action.registered" variant="warning" data-test="action-missing">{{ t.missing }}</Badge>
                </div>
              </TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(action.activeFlows) }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(action.versions) }}</TableCell>
              <template v-if="runs">
                <TableCell class="text-right tabular-nums">{{ count(action.executions) }}</TableCell>
                <TableCell :class="['text-right tabular-nums', (action.failures ?? 0) > 0 ? 'text-danger-foreground' : '']">{{ count(action.failures) }}</TableCell>
                <TableCell class="text-muted-foreground text-right whitespace-nowrap">{{ lastRun(action.lastRunAt) }}</TableCell>
              </template>
            </TableRow>
          </TableBody>
        </Table>
      </CardContent>
    </Card>

    <Card v-if="unused && unused.length > 0" data-test="node-usage-unused">
      <CardHeader>
        <CardTitle class="font-display">{{ t.unused.title }}</CardTitle>
        <CardDescription>{{ t.unused.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <ul class="flex flex-wrap gap-1.5">
          <li v-for="item in unused" :key="item.type">
            <Badge variant="neutral" :title="item.type">{{ item.label }}</Badge>
          </li>
        </ul>
      </CardContent>
    </Card>
  </div>
</template>
