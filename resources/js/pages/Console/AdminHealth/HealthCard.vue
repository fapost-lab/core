<script setup lang="ts">
import { computed } from 'vue'
import type { Component } from 'vue'
import { Badge } from '@fapost/ui/components/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { Skeleton } from '@fapost/ui/components/skeleton'
import { cn } from '@fapost/ui/lib/utils'
import { interpolate } from '@console/shell'
import { toneFor } from './health'
import type { HealthState, HealthTranslations } from './types'

/**
 * One service on the health page. It draws the state the probe reported, in words as well as colour, and says why when
 * the state is not "up". There is no default state: until the first answer the card is loading, which is neither
 * healthy nor broken, and a service that gave no answer is "unknown".
 */
const props = defineProps<{
  serviceKey: string
  title: string
  subtitle: string
  description: string
  icon: Component
  /** `loading` until the page has an answer. */
  state: HealthState | 'loading'
  reason: string | null
  /** Shown instead of "not reported" when a down or unknown service has no reason because it is withheld from this reader. */
  withheldReason?: string
  /** The sentence shown when the service is switched off on purpose. */
  disabledText: string
  /** When the answer is from, already written for the person; null when there is none. */
  checked: string | null
  t: HealthTranslations
}>()

const tone = computed(() => toneFor(props.state))
const hint = computed(() => (props.state === 'down' || props.state === 'unknown' || props.state === 'disabled' ? props.t.hint[props.state] : null))
const panel = computed(
  () =>
    ({
      success: 'bg-success text-success-foreground',
      danger: 'bg-danger text-danger-foreground',
      warning: 'bg-warning text-warning-foreground',
      neutral: 'bg-muted text-foreground',
    })[tone.value],
)
const dot = computed(
  () =>
    ({
      success: 'bg-success-dot',
      danger: 'bg-danger-dot',
      warning: 'bg-warning-dot',
      neutral: 'bg-neutral-dot',
    })[tone.value],
)
const reasonText = computed(() => {
  if (props.reason !== null) {
    return props.reason
  }

  if (props.withheldReason !== undefined && (props.state === 'down' || props.state === 'unknown')) {
    return props.withheldReason
  }

  return props.state === 'unknown' ? props.t.not_reported : null
})
const heading = computed(() => `health-${props.serviceKey}`)
</script>

<template>
  <Card :data-test="`card-${serviceKey}`" :data-state="state" role="region" :aria-labelledby="heading" :aria-busy="state === 'loading'">
    <CardHeader>
      <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
          <span class="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-lg" aria-hidden="true">
            <component :is="icon" class="size-5" />
          </span>
          <div class="min-w-0">
            <CardTitle :id="heading" class="font-display text-lg">{{ title }}</CardTitle>
            <p class="text-muted-foreground font-display text-[13px] tracking-wide uppercase">{{ subtitle }}</p>
          </div>
        </div>
        <Badge :variant="tone" data-test="state" aria-live="polite" :class="state === 'loading' && 'animate-pulse'">
          <span :class="cn('size-1.5 rounded-full', dot)" aria-hidden="true" />
          {{ t.state[state] }}
        </Badge>
      </div>
      <CardDescription>{{ description }}</CardDescription>
    </CardHeader>

    <CardContent class="flex flex-col gap-4">
      <div v-if="state === 'loading'" class="flex flex-col gap-3" data-test="loading">
        <p class="sr-only" role="status">{{ t.loading }}</p>
        <Skeleton class="h-14 w-full" />
        <div class="grid grid-cols-2 gap-3">
          <Skeleton class="h-14" />
          <Skeleton class="h-14" />
        </div>
      </div>

      <template v-else>
        <div :class="cn('flex flex-col gap-1 rounded-lg px-4 py-3', panel)" data-test="summary">
          <p class="font-medium">{{ t.summary[state] }}</p>
          <p v-if="state === 'disabled'" class="text-sm" data-test="disabled-text">{{ disabledText }}</p>
          <p v-if="hint" class="text-sm opacity-90" data-test="hint">{{ hint }}</p>
        </div>

        <slot />

        <div v-if="reasonText && state !== 'disabled'" class="flex flex-col gap-0.5" data-test="reason">
          <p class="text-muted-foreground text-[13px]">{{ t.reason }}</p>
          <p class="text-sm break-words">{{ reasonText }}</p>
        </div>

        <p class="text-muted-foreground text-[13px]" data-test="checked">
          {{ checked !== null ? interpolate(t.checked, { time: checked }) : t.never }}
        </p>
      </template>
    </CardContent>
  </Card>
</template>
