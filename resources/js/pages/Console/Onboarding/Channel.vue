<script lang="ts">
import OnboardingLayout from '../../../console/onboarding/OnboardingLayout.vue'

export default { layout: OnboardingLayout }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import type { OnboardingPageProps } from '../../../console/onboarding/types'
import ChannelForm from '../Channels/ChannelForm.vue'
import type { ChannelTypeOption, SettingsByType } from '../Channels/types'

/**
 * The optional channel step of the setup wizard: the console's channel form, with its cancel link turned into "skip".
 * Skipping writes nothing; the step stays open in the checklist.
 */
defineProps<{
  types: ChannelTypeOption[]
  settings: SettingsByType
  /** Where the channel can be connected later, worded by the server with the labels of the menu. */
  later: string
  urls: { submit: string; skip: string }
}>()

const page = usePage<OnboardingPageProps>()
const t = computed(() => page.props.translations.console.onboarding.channel)
const step = computed(() => page.props.translations.console.onboarding.steps.channel)
const skip = computed(() => page.props.translations.console.onboarding.skip)
</script>

<template>
  <Head :title="step.title" />

  <div class="flex flex-col gap-5">
    <div>
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
      <p class="text-muted-foreground mt-1 text-sm">{{ t.description }}</p>
      <p class="text-muted-foreground mt-1 text-sm">{{ later }}</p>
    </div>

    <ChannelForm mode="create" method="post" :submit-url="urls.submit" :cancel-url="urls.skip" :cancel-label="skip" :types="types" :settings="settings" />
  </div>
</template>
