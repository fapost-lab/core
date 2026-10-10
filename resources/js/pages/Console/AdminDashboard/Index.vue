<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { Head, router, usePage, usePoll } from '@inertiajs/vue3'
import { Activity, ArrowRightLeft, Clock, RefreshCw, Rocket, ShieldCheck, Signal, Users } from '@lucide/vue'
import type { Component } from 'vue'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@fapost/ui/shell'
import ActivityChart from './ActivityChart.vue'
import type { ActivityDay, AdminDashboardPageProps, AdminStats } from './types'

/** How long the page keeps refreshing its figures before it stops asking. */
const POLL_FOR_MS = 60 * 60 * 1000

const props = defineProps<{
  stats: AdminStats
  activity: ActivityDay[] | null
  pollSeconds: number
}>()

const page = usePage<AdminDashboardPageProps>()
const t = computed(() => page.props.translations.console.admin_dashboard)

const tiles = computed(() => {
  const s = props.stats
  const labels = t.value.stats
  const list: { key: string; label: string; value: number; hint: string; icon: Component }[] = []

  if (s.assistants) {
    list.push({ key: 'assistants', label: labels.assistants, value: s.assistants.total, hint: interpolate(labels.assistants_hint, { count: s.assistants.active }), icon: Rocket })
  }
  if (s.contacts) {
    list.push({ key: 'contacts', label: labels.contacts, value: s.contacts.total, hint: labels.contacts_hint, icon: Users })
  }
  if (s.channels) {
    list.push({ key: 'channels', label: labels.channels, value: s.channels.active, hint: interpolate(labels.channels_hint, { total: s.channels.total, active: s.channels.active }), icon: Signal })
  }
  if (s.flows) {
    list.push({ key: 'flows', label: labels.flows, value: s.flows.published, hint: labels.flows_hint, icon: ArrowRightLeft })
  }
  if (s.sessions) {
    list.push({ key: 'sessions', label: labels.sessions, value: s.sessions.waiting, hint: labels.sessions_hint, icon: Clock })
  }
  if (s.staff) {
    list.push({ key: 'staff', label: labels.staff, value: s.staff.total, hint: labels.staff_hint, icon: ShieldCheck })
  }

  return list
})

const number = computed(() => new Intl.NumberFormat(page.props.locale))
const nothing = computed(() => tiles.value.length === 0 && props.activity === null)

// The figures refresh as the Filament widgets polled (Inertia slows polling in a background tab); a page left open
// stops asking after an hour and says so, with a button that refreshes once and starts the hour again.
const poll = usePoll(props.pollSeconds * 1000, { only: ['stats', 'activity'] })
const stopped = ref(false)
const refreshing = ref(false)
let deadline = setTimeout(expire, POLL_FOR_MS)

function expire(): void {
  poll.stop()
  stopped.value = true
}

function refresh(): void {
  refreshing.value = true
  router.reload({
    only: ['stats', 'activity'],
    onFinish: () => {
      refreshing.value = false
    },
    onSuccess: () => {
      stopped.value = false
      poll.start()
      clearTimeout(deadline)
      deadline = setTimeout(expire, POLL_FOR_MS)
    },
  })
}

onBeforeUnmount(() => {
  clearTimeout(deadline)
  poll.stop()
})
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground text-sm">{{ t.description }}</p>
    </div>

    <div v-if="stopped" class="bg-surface-muted flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm" role="status" data-test="poll-stopped">
      <span class="text-muted-foreground">{{ t.stale }}</span>
      <Button variant="outline" size="sm" :disabled="refreshing" @click="refresh">
        <RefreshCw aria-hidden="true" :class="refreshing ? 'animate-spin' : ''" />
        {{ t.refresh }}
      </Button>
    </div>

    <EmptyState v-if="nothing" :title="t.empty" :description="t.empty_hint" :icon="Activity" class="rounded-xl border border-dashed py-12" />

    <div v-if="tiles.length > 0" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="stat-tiles">
      <Card v-for="tile in tiles" :key="tile.key" :data-test="`stat-${tile.key}`">
        <CardHeader>
          <CardDescription class="flex items-center gap-2">
            <component :is="tile.icon" aria-hidden="true" class="size-4" />
            {{ tile.label }}
          </CardDescription>
          <CardTitle class="font-display text-3xl font-semibold tabular-nums">{{ number.format(tile.value) }}</CardTitle>
        </CardHeader>
        <CardContent class="text-muted-foreground text-[13px]">{{ tile.hint }}</CardContent>
      </Card>
    </div>

    <Card v-if="activity">
      <CardHeader>
        <CardTitle class="font-display">{{ t.activity.title }}</CardTitle>
        <CardDescription>{{ t.activity.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <ActivityChart :days="activity" :locale="page.props.locale" :labels="t.activity" />
      </CardContent>
    </Card>
  </div>
</template>
