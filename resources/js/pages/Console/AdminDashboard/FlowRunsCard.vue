<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Workflow } from '@lucide/vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@fapost/ui/components/table'
import { interpolate } from '@console/shell'
import type { AdminDashboardPageProps, FlowRuns } from './types'

/**
 * Runs started, completed and failed per flow over a chosen period, the busiest flows first, as
 * `FlowRunAnalytics::perFlow()` reads them from `analytics_events`. Changing the period reloads only this card's prop;
 * the period stays in the address (the parameter the server names, `?runs_period`), so the dashboard's polling keeps it.
 */
const props = defineProps<{ runs: FlowRuns }>()

const page = usePage<AdminDashboardPageProps>()
const t = computed(() => page.props.translations.console.admin_dashboard.flow_runs)
const number = computed(() => new Intl.NumberFormat(page.props.locale))
const loading = ref(false)

const unattributed = computed(() => {
  const rest = props.runs.unattributed

  return rest !== null && rest.started + rest.completed + rest.failed > 0 ? rest : null
})
const empty = computed(() => props.runs.flows.length === 0 && unattributed.value === null)

function choose(value: unknown): void {
  const period = String(value)
  const query = Object.fromEntries(new URLSearchParams(window.location.search))

  if (period === props.runs.default) {
    delete query[props.runs.param]
  } else {
    query[props.runs.param] = period
  }

  loading.value = true
  router.get(window.location.pathname, query, {
    only: ['flowRuns'],
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onFinish: () => {
      loading.value = false
    },
  })
}
</script>

<template>
  <Card data-test="flow-runs">
    <CardHeader class="flex flex-row flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1.5">
        <CardTitle class="font-display flex items-center gap-2">
          <Workflow aria-hidden="true" class="size-4" />
          {{ t.title }}
        </CardTitle>
        <CardDescription>{{ t.description }}</CardDescription>
      </div>
      <Select :model-value="runs.period" :disabled="loading" @update:model-value="choose">
        <SelectTrigger size="sm" class="w-44" :aria-label="t.period" data-test="flow-runs-period">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in runs.periods" :key="option" :value="option">{{ t.periods[option] }}</SelectItem>
        </SelectContent>
      </Select>
    </CardHeader>

    <CardContent>
      <p v-if="empty" class="text-muted-foreground py-6 text-center text-sm" data-test="flow-runs-empty">{{ t.empty }}</p>

      <template v-else>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{{ t.flow }}</TableHead>
              <TableHead class="text-right">{{ t.started }}</TableHead>
              <TableHead class="text-right">{{ t.completed }}</TableHead>
              <TableHead class="text-right">{{ t.failed }}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="flow in runs.flows" :key="flow.flow_id" :data-test="`flow-runs-${flow.flow_id}`">
              <TableCell class="max-w-0 truncate font-medium" :title="flow.name || t.unnamed">{{ flow.name || t.unnamed }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(flow.started) }}</TableCell>
              <TableCell class="text-right tabular-nums">{{ number.format(flow.completed) }}</TableCell>
              <TableCell :class="['text-right tabular-nums', flow.failed > 0 ? 'text-danger-foreground' : '']">{{ number.format(flow.failed) }}</TableCell>
            </TableRow>
            <TableRow v-if="unattributed" data-test="flow-runs-unattributed">
              <TableCell class="text-muted-foreground max-w-0 truncate" :title="t.unattributed_hint">{{ t.unattributed }}</TableCell>
              <TableCell class="text-muted-foreground text-right tabular-nums">{{ number.format(unattributed.started) }}</TableCell>
              <TableCell class="text-muted-foreground text-right tabular-nums">{{ number.format(unattributed.completed) }}</TableCell>
              <TableCell class="text-muted-foreground text-right tabular-nums">{{ number.format(unattributed.failed) }}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
        <p v-if="runs.total > runs.flows.length" class="text-muted-foreground mt-3 text-[13px]" data-test="flow-runs-more">
          {{ interpolate(t.more, { shown: runs.flows.length, total: runs.total }) }}
        </p>
        <p v-if="unattributed" class="text-muted-foreground mt-3 text-[13px]">{{ t.unattributed_hint }}</p>
      </template>
    </CardContent>
  </Card>
</template>
