<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Gauge } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { interpolate } from '@console/shell'
import UsageBar from '../AdminUsage/UsageBar.vue'
import { badgeFor, barTone, hasBar, valueText } from '../AdminUsage/usage'
import type { UsageRowData } from '../AdminUsage/types'
import type { AdminDashboardPageProps, UsageWidgetData } from './types'

/**
 * A compact "Limits and usage" card for the dashboard: the limits closest to being used up, and a link to the full
 * page. Shown to whoever may open that page; the figures are those of the moment the dashboard was opened.
 */
const props = defineProps<{ usage: UsageWidgetData }>()

const page = usePage<AdminDashboardPageProps>()
const t = computed(() => page.props.translations.console.admin_dashboard.usage)
const labels = computed(() => page.props.translations.console.usage)

function meterLabel(row: UsageRowData): string {
  return interpolate(labels.value.meter, { name: row.label, percent: row.percent ?? 0 })
}
</script>

<template>
  <Card data-test="usage-widget">
    <CardHeader>
      <CardTitle class="font-display flex items-center gap-2">
        <Gauge aria-hidden="true" class="size-4" />
        {{ t.title }}
      </CardTitle>
      <CardDescription>{{ props.usage.limited ? (props.usage.rows.length > 0 ? t.description : '') : t.none }}</CardDescription>
    </CardHeader>

    <CardContent v-if="props.usage.rows.length > 0">
      <ul class="flex flex-col gap-4">
        <li v-for="row in props.usage.rows" :key="row.key" class="flex flex-col gap-1.5" :data-test="`usage-${row.key}`" :data-state="row.state">
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <span class="font-medium">{{ row.label }}</span>
            <span class="flex items-center gap-2">
              <Badge v-if="row.state === 'near' || row.state === 'reached'" :variant="badgeFor(row.state)!.variant">{{ labels.state[badgeFor(row.state)!.text] }}</Badge>
              <span class="text-[13px] tabular-nums">{{ valueText(row, labels) }}</span>
            </span>
          </div>
          <UsageBar v-if="hasBar(row)" :percent="row.percent ?? 0" :tone="barTone(row.state)" :label="meterLabel(row)" />
        </li>
      </ul>
      <p v-if="props.usage.total > props.usage.rows.length" class="text-muted-foreground mt-3 text-[13px]">
        {{ interpolate(t.more, { shown: props.usage.rows.length, total: props.usage.total }) }}
      </p>
    </CardContent>

    <CardContent v-if="props.usage.unavailable > 0">
      <p class="text-muted-foreground text-[13px]" data-test="usage-unavailable">{{ t.unavailable }}</p>
    </CardContent>

    <CardFooter>
      <Link :href="props.usage.url" class="text-primary text-sm font-medium hover:underline" data-test="usage-all">{{ t.all }}</Link>
    </CardFooter>
  </Card>
</template>
