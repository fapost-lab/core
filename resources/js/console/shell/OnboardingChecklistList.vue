<script setup lang="ts">
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Check, Circle } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import type { OnboardingChecklistData, ShellPageProps } from './types'

/**
 * The setup checklist's list, shared by the header's popover and the dashboard widget: each step with its state, a link
 * for the open ones, and the button that hides the checklist for the signed-in user.
 */
const props = defineProps<{ checklist: OnboardingChecklistData }>()

const page = usePage<ShellPageProps>()
const t = computed(() => page.props.translations.console.onboarding)

function title(key: string): string {
  return t.value.steps[key]?.title ?? key
}

function hide(): void {
  router.post(props.checklist.hideUrl, {}, { preserveScroll: true })
}
</script>

<template>
  <div class="flex flex-col gap-3" data-test="onboarding-checklist">
    <ul class="flex flex-col gap-2">
      <li v-for="item in checklist.items" :key="item.key" class="flex items-center gap-2 text-sm" :data-test="`onboarding-step-${item.key}`">
        <Check v-if="item.complete" aria-hidden="true" class="text-success size-4 shrink-0" />
        <Circle v-else aria-hidden="true" class="text-muted-foreground size-4 shrink-0" />
        <span v-if="item.complete" class="text-muted-foreground min-w-0 flex-1 truncate line-through">{{ title(item.key) }}</span>
        <a v-else :href="item.url" class="min-w-0 flex-1 truncate underline-offset-4 hover:underline">{{ title(item.key) }}</a>
        <span v-if="!item.required" class="text-faint-foreground shrink-0 text-xs">{{ t.checklist.optional }}</span>
      </li>
    </ul>
    <div class="flex justify-end">
      <Button variant="ghost" size="sm" data-test="onboarding-checklist-hide" @click="hide">{{ t.checklist.hide }}</Button>
    </div>
  </div>
</template>
