<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Badge, StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { interpolate } from '@console/shell'
import { changeText, mismatchText, registrationLabel } from './format'
import type { SolutionRow, SolutionsPageProps } from './types'

/** The full picture of one Solution: description, who last changed it, the entitlement note, interface mismatches and what it adds. */
const props = defineProps<{
  solution: SolutionRow | null
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<SolutionsPageProps>()
const t = computed(() => page.props.translations.console.solutions)

const identity = computed(() => {
  const solution = props.solution

  if (!solution) {
    return ''
  }

  return `${solution.version ? `${interpolate(t.value.version, { version: solution.version })} · ` : ''}${interpolate(t.value.package, { package: solution.package })}`
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg" data-test="details-dialog">
      <template v-if="solution">
        <DialogHeader>
          <DialogTitle class="font-display flex flex-wrap items-center gap-3">
            {{ solution.name }}
            <StatusDot v-if="solution.entitlement.paused" tone="warning">{{ t.entitlement.paused }}</StatusDot>
            <StatusDot v-else :tone="solution.active ? 'success' : 'neutral'">{{ solution.active ? t.state.active : t.state.inactive }}</StatusDot>
          </DialogTitle>
          <DialogDescription>{{ solution.description ?? identity }}</DialogDescription>
        </DialogHeader>

        <div class="flex flex-col gap-4">
          <p v-if="solution.description" class="text-muted-foreground text-[13px]">{{ identity }}</p>

          <p v-if="solution.lastChange" class="text-muted-foreground text-[13px]" data-test="last-change">{{ changeText(t, solution.lastChange) }}</p>

          <div v-if="solution.entitlement.state === 'not_entitled'" class="flex flex-col items-start gap-2" data-test="entitlement">
            <p class="text-sm">{{ solution.entitlement.paused ? t.entitlement.paused_note : t.entitlement.off_note }}</p>
            <Button
              v-if="solution.entitlement.action"
              as="a"
              :href="solution.entitlement.action.url"
              target="_blank"
              rel="noopener noreferrer"
              variant="outline"
              size="sm"
              data-test="entitlement-action"
            >
              {{ solution.entitlement.action.label }}
            </Button>
          </div>

          <div v-if="solution.ui && solution.ui.mismatches.length > 0" class="flex flex-col gap-1.5" data-test="kit-mismatch">
            <Badge variant="warning" class="self-start">{{ t.kit.title }}</Badge>
            <p class="text-muted-foreground text-sm">{{ t.kit.description }}</p>
            <ul class="text-sm">
              <li v-for="mismatch in solution.ui.mismatches" :key="mismatch.component" :data-test="`mismatch-${mismatch.component}`">{{ mismatchText(t, mismatch) }}</li>
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
                  <Badge variant="info">{{ registrationLabel(t, registration.point, registration.label, registration.key) }}</Badge>
                </li>
              </ul>
              <p v-if="solution.ui.pages.length > 0" class="text-muted-foreground text-[13px]">{{ t.ui.pages }}: {{ solution.ui.pages.join(', ') }}</p>
            </div>
          </div>
        </div>
      </template>
    </DialogContent>
  </Dialog>
</template>
