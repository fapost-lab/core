<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { Gauge } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@console/shell'
import UsageBar from './UsageBar.vue'
import type { UsageAction, UsagePageProps, UsageRowData } from './types'
import { badgeFor, barTone, groupByKind, hasBar, sharedPeriodEnd, valueText } from './usage'

const props = defineProps<{
  rows: UsageRowData[]
  action: UsageAction | null
  limited: boolean
}>()

const page = usePage<UsagePageProps>()
const t = computed(() => page.props.translations.console.usage)

const sections = computed(() =>
  groupByKind(props.rows).map(({ kind, rows }) => {
    const copy = t.value.sections[kind]
    const until = kind === 'per_period' ? sharedPeriodEnd(rows) : null

    return {
      kind,
      rows,
      title: copy.title,
      description: until !== null && copy.until ? interpolate(copy.until, { date: until }) : copy.description,
      perRowPeriod: kind === 'per_period' && until === null,
    }
  }),
)

function meterLabel(row: UsageRowData): string {
  return interpolate(t.value.meter, { name: row.label, percent: row.percent ?? 0 })
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

      <Button v-if="action" as="a" :href="action.url" rel="noopener" data-test="operator-action">{{ action.label }}</Button>
    </div>

    <p v-if="rows.length > 0 && !limited" class="text-muted-foreground text-sm" data-test="no-limits">{{ t.no_limits }}</p>

    <EmptyState v-if="rows.length === 0" :icon="Gauge" :title="t.empty" :description="t.empty_hint" class="rounded-xl border border-dashed py-12" />

    <Card v-for="section in sections" :key="section.kind" :data-test="`section-${section.kind}`">
      <CardHeader>
        <CardTitle class="font-display">{{ section.title }}</CardTitle>
        <CardDescription>{{ section.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <ul class="flex flex-col gap-5">
          <li v-for="row in section.rows" :key="row.key" class="flex flex-col gap-2" :data-test="`row-${row.key}`" :data-state="row.state">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <span class="font-medium">{{ row.label }}</span>
              <span class="tabular-nums" data-test="value">{{ valueText(row, t) }}</span>
            </div>

            <UsageBar v-if="hasBar(row)" :percent="row.percent ?? 0" :tone="barTone(row.state)" :label="meterLabel(row)" />

            <div class="flex flex-wrap items-center gap-2">
              <Badge v-if="badgeFor(row.state)" :variant="badgeFor(row.state)!.variant" data-test="state">{{ t.state[badgeFor(row.state)!.text] }}</Badge>
              <span v-if="section.perRowPeriod && row.periodEndsLabel && t.sections.per_period.until" class="text-muted-foreground text-[13px]">
                {{ interpolate(t.sections.per_period.until, { date: row.periodEndsLabel }) }}
              </span>
            </div>
          </li>
        </ul>
      </CardContent>
    </Card>
  </div>
</template>
