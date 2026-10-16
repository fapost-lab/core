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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { interpolate } from '@console/shell'
import type { LastChange, MissingRow, RangeMismatch, SolutionRow, SolutionsPageProps } from './types'

/**
 * The installed Solutions and whether this workspace has each one on. Turning one on is a single click; turning one off
 * asks first, because flows that call its actions stop. Both are POSTs to the server, which decides whether they are
 * allowed; the buttons only reflect what the server already said.
 */
defineProps<{
  solutions: SolutionRow[]
  missing: MissingRow[]
}>()

const page = usePage<SolutionsPageProps>()
const t = computed(() => page.props.translations.console.solutions)
const common = computed(() => page.props.translations.console.form)

const busy = ref(false)
const toTurnOff = ref<{ name: string; url: string } | null>(null)
const confirmOpen = ref(false)

function send(url: string): void {
  if (busy.value) {
    return
  }

  busy.value = true
  router.post(url, {}, { preserveScroll: true, onFinish: () => (busy.value = false) })
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

function changeText(change: LastChange): string {
  return interpolate(t.value.last_change[change.action], { actor: change.actor ?? t.value.last_change.unknown, date: change.atLabel })
}

function mismatchText(mismatch: RangeMismatch): string {
  return interpolate(mismatch.unreadable ? t.value.kit.unreadable : t.value.kit.mismatch, {
    runtime: t.value.kit.runtime[mismatch.component],
    required: mismatch.required,
    provided: mismatch.provided,
  })
}

function registrationLabel(point: keyof typeof t.value.points, label: string | null, key: string): string {
  return `${t.value.points[point]}: ${label ?? key}`
}
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-1">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground text-sm">{{ t.description }}</p>
    </div>

    <EmptyState
      v-if="solutions.length === 0 && missing.length === 0"
      :icon="Puzzle"
      :title="t.empty"
      :description="t.empty_hint"
      class="rounded-xl border border-dashed py-12"
    />

    <Card v-for="solution in solutions" :key="solution.id" :data-test="`solution-${solution.id}`" :data-active="solution.active">
      <CardHeader>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex flex-col gap-1">
            <CardTitle class="font-display flex flex-wrap items-center gap-3">
              {{ solution.name }}
              <StatusDot :tone="solution.active ? 'success' : 'neutral'" data-test="state">
                {{ solution.active ? t.state.active : t.state.inactive }}
              </StatusDot>
            </CardTitle>
            <CardDescription v-if="solution.description">{{ solution.description }}</CardDescription>
            <p class="text-muted-foreground text-[13px]">
              <span v-if="solution.version">{{ interpolate(t.version, { version: solution.version }) }} · </span>{{ interpolate(t.package, { package: solution.package }) }}
            </p>
          </div>

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
        </div>
      </CardHeader>

      <CardContent class="flex flex-col gap-4">
        <p v-if="solution.lastChange" class="text-muted-foreground text-[13px]" data-test="last-change">{{ changeText(solution.lastChange) }}</p>

        <div v-if="solution.ui && solution.ui.mismatches.length > 0" class="flex flex-col gap-1.5" data-test="kit-mismatch">
          <Badge variant="warning" class="self-start">{{ t.kit.title }}</Badge>
          <p class="text-muted-foreground text-sm">{{ t.kit.description }}</p>
          <ul class="text-sm">
            <li v-for="mismatch in solution.ui.mismatches" :key="mismatch.component" :data-test="`mismatch-${mismatch.component}`">{{ mismatchText(mismatch) }}</li>
          </ul>
        </div>

        <div class="flex flex-col gap-1.5">
          <h2 class="text-sm font-medium">{{ t.registers.title }}</h2>

          <p v-if="solution.actions.length === 0 && !solution.ui" class="text-muted-foreground text-sm" data-test="registers-none">{{ t.registers.none }}</p>

          <div v-if="solution.actions.length > 0" class="flex flex-col gap-1" data-test="actions">
            <span class="text-muted-foreground text-[13px]">{{ t.registers.actions }}</span>
            <ul class="flex flex-wrap gap-1.5">
              <li v-for="action in solution.actions" :key="action"><Badge variant="neutral" class="font-mono">{{ action }}</Badge></li>
            </ul>
          </div>

          <div v-if="solution.ui" class="flex flex-col gap-1" data-test="ui">
            <span class="text-muted-foreground text-[13px]">{{ t.ui.title }}</span>
            <p v-if="!solution.active && solution.ui.state === 'published'" class="text-muted-foreground text-[13px]">{{ t.ui.inactive_note }}</p>
            <p v-if="solution.ui.state === 'not_published'" class="text-sm" data-test="not-published">{{ t.ui.not_published }}</p>
            <p v-if="solution.ui.application" class="text-sm">{{ interpolate(t.ui.application, { label: solution.ui.application }) }}</p>
            <ul v-if="solution.ui.registrations.length > 0" class="flex flex-wrap gap-1.5">
              <li v-for="registration in solution.ui.registrations" :key="`${registration.point}.${registration.key}`">
                <Badge variant="info">{{ registrationLabel(registration.point, registration.label, registration.key) }}</Badge>
              </li>
            </ul>
            <p v-if="solution.ui.pages.length > 0" class="text-muted-foreground text-[13px]">{{ t.ui.pages }}: {{ solution.ui.pages.join(', ') }}</p>
          </div>
        </div>
      </CardContent>
    </Card>

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
              <span v-if="row.lastChange" class="text-muted-foreground text-[13px]">{{ changeText(row.lastChange) }}</span>
            </div>
            <Button variant="outline" :disabled="busy" data-test="deactivate" @click="send(row.urls.deactivate)">{{ t.deactivate }}</Button>
          </li>
        </ul>
      </CardContent>
    </Card>

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
