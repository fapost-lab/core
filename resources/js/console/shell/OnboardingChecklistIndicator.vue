<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { ListChecks } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { interpolate } from '@fapost/ui/lib/utils'
import OnboardingChecklistList from './OnboardingChecklistList.vue'
import type { OnboardingChecklistData, ShellPageProps } from './types'

/** The header's entry to the setup checklist: an icon with the number of steps left, opening the list. */
defineProps<{ checklist: OnboardingChecklistData }>()

const page = usePage<ShellPageProps>()
const t = computed(() => page.props.translations.console.onboarding.checklist)
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="icon-sm" class="relative" :aria-label="`${t.open} (${interpolate(t.remaining, { count: checklist.remaining })})`" data-test="onboarding-checklist-indicator">
        <ListChecks aria-hidden="true" />
        <span class="bg-primary text-primary-foreground absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full text-[10px] leading-none font-medium tabular-nums">
          {{ checklist.remaining }}
        </span>
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-80 p-4">
      <p class="font-display mb-3 text-sm font-semibold">{{ t.title }}</p>
      <OnboardingChecklistList :checklist="checklist" />
    </DropdownMenuContent>
  </DropdownMenu>
</template>
