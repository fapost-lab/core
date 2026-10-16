<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Puzzle } from '@lucide/vue'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@console/shell'
import DetailsDialog from './DetailsDialog.vue'
import { changeText } from './format'
import type { MissingRow, SolutionRow, SolutionsPageProps } from './types'

/**
 * The installed Solutions and whether this workspace has each one on. Turning one on is a single click; turning one off
 * asks first, because flows that call its actions stop. Both are POSTs to the server, which decides whether they are
 * allowed; the buttons only reflect what the server already said. A Solution the plan or an add-on grants is on with no
 * button; one that is not connected shows Connect when the operator offers it, and otherwise cannot be turned on. One
 * turned on before it stopped being connected can still be turned off from Details.
 */
const props = defineProps<{
  solutions: SolutionRow[]
  missing: MissingRow[]
}>()

const page = usePage<SolutionsPageProps>()
const t = computed(() => page.props.translations.console.solutions)
const common = computed(() => page.props.translations.console.form)

const entitlementUnavailable = computed(() => props.solutions.some((solution) => solution.entitlement.state === 'unavailable'))

const busy = ref(false)
const toTurnOff = ref<{ name: string; url: string } | null>(null)
const confirmOpen = ref(false)

// The dialog reads the row from the props by id, so it stays current after a toggle refreshes them.
const selectedId = ref<string | null>(null)
const detailsOpen = ref(false)
const selected = computed(() => props.solutions.find((solution) => solution.id === selectedId.value) ?? null)

function openDetails(solution: SolutionRow): void {
  selectedId.value = solution.id
  detailsOpen.value = true
}

function send(url: string): void {
  if (busy.value) {
    return
  }

  busy.value = true
  router.post(url, {}, { preserveScroll: true, onFinish: () => (busy.value = false) })
}

/** Which footer a card shows; the first match wins, and the server alone decides each of these. */
type CardState = 'plan' | 'addon' | 'pending' | 'offer' | 'unavailable_to_connect' | 'switch'

function cardState(solution: SolutionRow): CardState {
  if (solution.grant) {
    return solution.grant.source
  }

  if (solution.entitlement.state === 'not_entitled') {
    if (!solution.offer) {
      return 'unavailable_to_connect'
    }

    return solution.offer.requestPending ? 'pending' : 'offer'
  }

  return 'switch'
}

/** Whether the card says "On": a granted Solution always runs, a not-entitled one never does, even if it is stored as on. */
function isOn(solution: SolutionRow): boolean {
  return solution.grant !== null || (solution.entitlement.state !== 'not_entitled' && solution.active)
}

function grantBadge(solution: SolutionRow): string {
  const grant = solution.grant

  if (!grant) {
    return ''
  }

  if (grant.source === 'plan') {
    return t.value.grant.plan_badge
  }

  return grant.endsAtLabel ? `${t.value.grant.addon_badge} · ${interpolate(t.value.grant.until, { date: grant.endsAtLabel })}` : t.value.grant.addon_badge
}

function connectLabel(offer: NonNullable<SolutionRow['offer']>): string {
  return offer.price ? interpolate(t.value.offer.connect_price, { price: offer.price }) : t.value.offer.connect
}

function askToTurnOff(name: string, url: string): void {
  toTurnOff.value = { name, url }
  confirmOpen.value = true
}

function turnOff(): void {
  if (toTurnOff.value) {
    send(toTurnOff.value.url)
  }
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground text-sm">{{ t.description }}</p>
    </div>

    <p v-if="entitlementUnavailable" class="text-muted-foreground rounded-lg border px-4 py-3 text-sm" data-test="entitlement-unavailable">
      {{ t.entitlement.unavailable_note }}
    </p>

    <EmptyState
      v-if="solutions.length === 0 && missing.length === 0"
      :icon="Puzzle"
      :title="t.empty"
      :description="t.empty_hint"
      class="rounded-xl border border-dashed py-12"
    />

    <div v-if="solutions.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-test="solutions-grid">
      <Card v-for="solution in solutions" :key="solution.id" class="h-full" :data-test="`solution-${solution.id}`" :data-active="solution.active">
        <CardHeader>
          <CardTitle class="font-display flex flex-wrap items-center gap-x-3 gap-y-1.5">
            {{ solution.name }}
            <StatusDot :tone="isOn(solution) ? 'success' : 'neutral'" data-test="state">
              {{ isOn(solution) ? t.state.active : t.state.inactive }}
            </StatusDot>
            <Badge v-if="solution.grant" variant="success" data-test="grant-badge">{{ grantBadge(solution) }}</Badge>
            <Badge v-if="solution.entitlement.state === 'not_entitled'" variant="warning" data-test="not-entitled">{{ t.entitlement.badge }}</Badge>
            <Badge v-if="solution.ui && solution.ui.mismatches.length > 0" variant="warning" data-test="kit-badge">{{ t.kit.badge }}</Badge>
          </CardTitle>
        </CardHeader>

        <CardContent class="flex flex-col gap-1">
          <p v-if="solution.description" class="text-muted-foreground truncate text-sm" :title="solution.description" data-test="summary">{{ solution.description }}</p>
          <p class="text-muted-foreground truncate text-[13px]">
            <span v-if="solution.version">{{ interpolate(t.version, { version: solution.version }) }} · </span>{{ interpolate(t.package, { package: solution.package }) }}
          </p>
        </CardContent>

        <CardFooter class="mt-auto flex flex-wrap gap-2">
          <template v-if="cardState(solution) === 'switch'">
            <Button
              v-if="solution.active"
              variant="outline"
              :disabled="busy"
              data-test="deactivate"
              @click="askToTurnOff(solution.name, solution.urls.deactivate)"
            >
              {{ t.deactivate }}
            </Button>
            <Button v-else :disabled="busy || !solution.canActivate" data-test="activate" @click="send(solution.urls.activate)">{{ t.activate }}</Button>
          </template>
          <Button v-else-if="cardState(solution) === 'pending'" disabled data-test="request-sent">{{ t.offer.request_sent }}</Button>
          <Button v-else-if="cardState(solution) === 'offer' && solution.offer" :disabled="busy" data-test="connect" @click="send(solution.urls.connect)">
            {{ connectLabel(solution.offer) }}
          </Button>
          <p v-else-if="cardState(solution) === 'unavailable_to_connect'" class="text-muted-foreground self-center text-[13px]" data-test="not-available">
            {{ t.entitlement.off_note }}
          </p>
          <Button variant="ghost" data-test="details" @click="openDetails(solution)">{{ t.details }}</Button>
        </CardFooter>
      </Card>
    </div>

    <Card v-if="missing.length > 0" data-test="missing">
      <CardHeader>
        <CardTitle class="font-display">{{ t.missing.title }}</CardTitle>
        <CardDescription>{{ t.missing.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <ul class="flex flex-col gap-3">
          <li v-for="row in missing" :key="row.id" class="flex flex-wrap items-center justify-between gap-3" :data-test="`missing-${row.id}`">
            <div class="flex flex-col">
              <span class="font-mono text-sm">{{ row.id }}</span>
              <span v-if="row.lastChange" class="text-muted-foreground text-[13px]">{{ changeText(t, row.lastChange) }}</span>
            </div>
            <Button variant="outline" :disabled="busy" data-test="deactivate" @click="send(row.urls.deactivate)">{{ t.deactivate }}</Button>
          </li>
        </ul>
      </CardContent>
    </Card>

    <DetailsDialog
      v-model:open="detailsOpen"
      :solution="selected"
      :busy="busy"
      @connect="selected && send(selected.urls.connect)"
      @deactivate="selected && askToTurnOff(selected.name, selected.urls.deactivate)"
    />

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="interpolate(t.deactivate_dialog.title, { name: toTurnOff?.name ?? '' })"
      :description="t.deactivate_dialog.description"
      :confirm-label="t.deactivate_dialog.confirm"
      :cancel-label="common.cancel"
      @confirm="turnOff"
    />
  </div>
</template>
