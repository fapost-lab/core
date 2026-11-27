<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Megaphone } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@console/shell'
import { BROADCAST_TONES } from '../Broadcasts/tones'
import type { BroadcastsTranslations } from '../Broadcasts/types'
import type { AdminDashboardPageProps, DashboardBroadcast } from './types'

/**
 * The latest broadcast runs of the assistants the user may open, with how far each got; a run's name opens its report.
 * Shown to whoever may manage broadcasts, refreshed with the dashboard's figures.
 */
const props = defineProps<{ broadcasts: DashboardBroadcast[] }>()

const page = usePage<AdminDashboardPageProps & { translations: { console: { broadcasts: BroadcastsTranslations } } }>()
const t = computed(() => page.props.translations.console.admin_dashboard.broadcasts)
const labels = computed(() => page.props.translations.console.broadcasts)
</script>

<template>
  <Card data-test="broadcasts-widget">
    <CardHeader>
      <CardTitle class="font-display flex items-center gap-2">
        <Megaphone aria-hidden="true" class="size-4" />
        {{ t.title }}
      </CardTitle>
      <CardDescription>{{ props.broadcasts.length > 0 ? t.description : t.empty }}</CardDescription>
    </CardHeader>

    <CardContent v-if="props.broadcasts.length > 0">
      <ul class="flex flex-col divide-y">
        <li v-for="broadcast in props.broadcasts" :key="broadcast.id" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 py-3 first:pt-0 last:pb-0" :data-test="`broadcast-${broadcast.id}`">
          <div class="flex min-w-0 flex-col gap-0.5">
            <Link :href="broadcast.reportUrl" class="truncate font-medium hover:underline">{{ broadcast.name }}</Link>
            <span class="text-muted-foreground truncate text-[13px]">
              {{ broadcast.assistantName }}<template v-if="broadcast.startedAt"> · <span :title="broadcast.startedAt">{{ relativeTime(broadcast.startedAt, page.props.locale) }}</span></template>
            </span>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-[13px]">
            <StatusDot :tone="BROADCAST_TONES[broadcast.status]">{{ labels.statuses[broadcast.status] }}</StatusDot>
            <span v-if="broadcast.status === 'running' && broadcast.total === 0" class="text-muted-foreground">{{ t.preparing }}</span>
            <span v-else class="tabular-nums">{{ interpolate(t.progress, { sent: broadcast.sent, total: broadcast.total }) }}</span>
            <Badge v-if="broadcast.failed > 0" variant="danger" class="tabular-nums">{{ interpolate(t.failed, { count: broadcast.failed }) }}</Badge>
            <Badge v-if="broadcast.skipped > 0" variant="neutral" class="tabular-nums">{{ interpolate(t.skipped, { count: broadcast.skipped }) }}</Badge>
          </div>
        </li>
      </ul>
    </CardContent>
  </Card>
</template>
