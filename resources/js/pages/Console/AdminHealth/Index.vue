<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Radio, RefreshCw, TriangleAlert, Webhook } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { interpolate } from '@console/shell'
import Figure from './Figure.vue'
import HealthCard from './HealthCard.vue'
import { formatChecked, newestCheck } from './health'
import type { HealthPageProps, HealthReportData } from './types'

const props = defineProps<{
  pollSeconds: number
  /** Absent on the first visit: the page asks for it itself, so the loading state and a failed request are its own. */
  report?: HealthReportData
}>()

const page = usePage<HealthPageProps>()
const t = computed(() => page.props.translations.console.health)
const locale = computed(() => page.props.locale ?? 'en')

/** The last answer that arrived. A failed refresh keeps it on screen, marked as out of date, instead of blanking it. */
const snapshot = shallowRef<HealthReportData | null>(props.report ?? null)
const failed = ref(false)
const refreshing = ref(false)
/** When an answer last arrived. A report restored from history at mount is not one, so it counts as old until a fetch lands. */
const lastSuccessAt = ref<number | null>(null)
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
let clock: ReturnType<typeof setInterval> | undefined
let requestTimer: ReturnType<typeof setTimeout> | undefined
let generation = 0

/** A request that has not finished by then is given up on, so one hung connection cannot freeze the page. */
const REQUEST_TIMEOUT_MS = 10_000

watch(
  () => props.report,
  (report) => {
    if (report) {
      snapshot.value = report
    }
  },
)

/**
 * Asks for the report with a partial reload. Polling is Inertia over HTTP on purpose: this page reports on the
 * websocket, so it must not need the websocket to stay current.
 */
function refresh(): void {
  if (refreshing.value) {
    return
  }

  refreshing.value = true
  const mine = ++generation
  const current = (): boolean => mine === generation

  clearTimeout(requestTimer)
  requestTimer = setTimeout(() => {
    if (!current()) {
      return
    }

    generation++
    router.cancelAll()
    failed.value = true
    refreshing.value = false
  }, REQUEST_TIMEOUT_MS)

  router.reload({
    only: ['report'],
    onSuccess: () => {
      if (current()) {
        failed.value = false
        lastSuccessAt.value = Date.now()
        now.value = lastSuccessAt.value
      }
    },
    onHttpException: () => {
      if (current()) {
        failed.value = true
      }

      return false
    },
    onNetworkError: () => {
      if (current()) {
        failed.value = true
      }

      return false
    },
    onFinish: () => {
      if (current()) {
        clearTimeout(requestTimer)
        refreshing.value = false
      }
    },
  })
}

function refreshIfVisible(): void {
  if (document.visibilityState === 'visible') {
    refresh()
  }
}

onMounted(() => {
  refresh()
  timer = setInterval(refreshIfVisible, props.pollSeconds * 1000)
  clock = setInterval(() => {
    now.value = Date.now()
  }, 1000)
  document.addEventListener('visibilitychange', refreshIfVisible)
})

onBeforeUnmount(() => {
  clearInterval(timer)
  clearInterval(clock)
  clearTimeout(requestTimer)
  document.removeEventListener('visibilitychange', refreshIfVisible)
})

const reverb = computed(() => snapshot.value?.reverb ?? null)
const gateway = computed(() => snapshot.value?.gateway ?? null)
const noData = computed(() => snapshot.value === null && failed.value)
/** Old data is never shown as current: a failed request, no answer yet for a restored report, or no answer for two intervals. */
const stale = computed(
  () => snapshot.value !== null && (failed.value || lastSuccessAt.value === null || now.value - lastSuccessAt.value > props.pollSeconds * 2000),
)
const lastKnown = computed(() => formatChecked(newestCheck([reverb.value?.checkedAt ?? null, gateway.value?.checkedAt ?? null]), locale.value))

function checkedLabel(iso: string | null | undefined): string | null {
  return formatChecked(iso ?? null, locale.value)
}

function specVariant(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  return status === 'matches' ? 'success' : status === 'differs' ? 'warning' : status === 'missing' ? 'danger' : 'neutral'
}

function specText(status: string): string {
  return (t.value.gateway.spec_status as Record<string, string>)[status] ?? status
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex max-w-2xl flex-col gap-1">
        <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
        <p class="text-muted-foreground text-sm">{{ t.description }}</p>
      </div>

      <div class="flex flex-col items-end gap-1">
        <Button variant="outline" :disabled="refreshing" data-test="refresh" @click="refresh">
          <RefreshCw :class="refreshing ? 'animate-spin' : ''" aria-hidden="true" />
          {{ refreshing ? t.refreshing : t.refresh }}
        </Button>
        <p class="text-muted-foreground text-[13px]" data-test="polling">{{ interpolate(t.polling, { seconds: pollSeconds }) }}</p>
      </div>
    </div>

    <div v-if="stale" class="border-warning-dot/40 bg-warning text-warning-foreground flex items-start gap-3 rounded-xl border px-4 py-3" role="alert" data-test="stale">
      <TriangleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
      <div class="flex flex-1 flex-col gap-0.5">
        <p class="font-medium">{{ t.stale.title }}</p>
        <p class="text-sm">{{ interpolate(t.stale.body, { time: lastKnown ?? t.never, seconds: pollSeconds }) }}</p>
      </div>
    </div>

    <div v-if="noData" class="border-danger-dot/40 bg-danger text-danger-foreground flex flex-col items-start gap-3 rounded-xl border px-5 py-6" role="alert" data-test="no-data">
      <div class="flex items-start gap-3">
        <TriangleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <div class="flex flex-col gap-0.5">
          <p class="font-medium">{{ t.stale.title }}</p>
          <p class="text-sm">{{ interpolate(t.stale.nodata, { seconds: pollSeconds }) }}</p>
        </div>
      </div>
      <Button variant="outline" :disabled="refreshing" data-test="retry" @click="refresh">{{ t.stale.retry }}</Button>
    </div>

    <div v-else :class="['grid gap-5 lg:grid-cols-2', stale ? 'opacity-70' : '']">
      <HealthCard
        service-key="reverb"
        :title="t.reverb.title"
        :subtitle="t.reverb.subtitle"
        :description="t.reverb.description"
        :icon="Radio"
        :state="reverb?.state ?? 'loading'"
        :reason="reverb?.reason ?? null"
        :disabled-text="t.reverb.disabled"
        :checked="checkedLabel(reverb?.checkedAt)"
        :t="t"
      >
        <dl v-if="reverb && reverb.state !== 'disabled'" class="grid grid-cols-2 gap-3">
          <Figure :label="t.reverb.connections" :value="reverb.connections" :missing="t.no_figure" />
          <Figure :label="t.reverb.channels" :value="reverb.channels" :missing="t.no_figure" />
          <Figure v-if="reverb.address" class="col-span-2" :label="t.reverb.address" :value="reverb.address" :missing="t.no_figure">
            <span class="font-mono text-sm font-normal break-all">{{ reverb.address }}</span>
          </Figure>
        </dl>
      </HealthCard>

      <HealthCard
        service-key="gateway"
        :title="t.gateway.title"
        :subtitle="t.gateway.subtitle"
        :description="t.gateway.description"
        :icon="Webhook"
        :state="gateway?.state ?? 'loading'"
        :reason="gateway?.reason ?? null"
        :disabled-text="t.gateway.disabled"
        :checked="checkedLabel(gateway?.checkedAt)"
        :t="t"
      >
        <dl v-if="gateway && gateway.state !== 'disabled'" class="grid gap-3">
          <Figure v-if="gateway.url" :label="t.gateway.address" :value="gateway.url" :missing="t.no_figure">
            <span class="font-mono text-sm font-normal break-all">{{ gateway.url }}</span>
          </Figure>

          <div class="bg-muted/50 flex flex-col gap-2 rounded-lg px-4 py-3" data-test="specs">
            <dt class="text-muted-foreground text-[13px]">{{ t.gateway.specs }}</dt>
            <dd v-if="gateway.specs === null" class="text-muted-foreground text-sm" data-test="missing">{{ t.gateway.specs_none }}</dd>
            <dd v-else-if="gateway.specs.length === 0" class="text-muted-foreground text-sm" data-test="missing">{{ t.no_figure }}</dd>
            <dd v-else class="flex flex-wrap gap-2">
              <Badge v-for="spec in gateway.specs" :key="spec.platform" :variant="specVariant(spec.status)" :data-test="`spec-${spec.platform}`">
                {{ spec.platform }}: {{ specText(spec.status) }}
              </Badge>
            </dd>
          </div>

          <div class="bg-muted/50 flex flex-col gap-1 rounded-lg px-4 py-3" data-test="drift">
            <dt class="text-muted-foreground text-[13px]">{{ t.gateway.drift }}</dt>
            <dd v-if="gateway.drift === null" class="text-muted-foreground text-sm" data-test="missing">{{ t.gateway.drift_none }}</dd>
            <dd v-else-if="gateway.drift.total === 0" class="text-sm" data-test="drift-zero">{{ t.gateway.drift_zero }}</dd>
            <dd v-else class="flex flex-col gap-1">
              <span class="font-display text-2xl leading-tight font-semibold tabular-nums" data-test="drift-total">{{ gateway.drift.total }}</span>
              <span class="text-sm">{{ t.gateway.drift_some }}</span>
              <span class="text-muted-foreground text-[13px]">{{ gateway.drift.platforms.map((p) => `${p.platform}: ${p.count}`).join(', ') }}</span>
            </dd>
          </div>
        </dl>
      </HealthCard>
    </div>
  </div>
</template>
