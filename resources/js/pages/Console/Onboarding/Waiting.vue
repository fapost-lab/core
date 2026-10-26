<script lang="ts">
import OnboardingLayout from '../../../console/onboarding/OnboardingLayout.vue'

export default { layout: OnboardingLayout }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { Hourglass } from '@lucide/vue'
import type { OnboardingPageProps } from '../../../console/onboarding/types'

/** What a user without the right to manage settings sees while the wizard is pending: a calm wait, never a 403. */
const page = usePage<OnboardingPageProps>()
const t = computed(() => page.props.translations.console.onboarding.waiting)
</script>

<template>
  <Head :title="t.title" />

  <div class="flex flex-col items-center gap-3 py-12 text-center">
    <Hourglass class="text-muted-foreground size-8" aria-hidden="true" />
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
    <p class="text-muted-foreground max-w-md text-sm">{{ t.description }}</p>
    <p class="text-faint-foreground text-xs">{{ t.hint }}</p>
  </div>
</template>
