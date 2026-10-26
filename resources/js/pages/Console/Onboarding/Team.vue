<script lang="ts">
import OnboardingLayout from '../../../console/onboarding/OnboardingLayout.vue'

export default { layout: OnboardingLayout }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import type { OnboardingPageProps } from '../../../console/onboarding/types'
import InviteForm from '../Users/InviteForm.vue'
import type { RoleOption } from '../Users/types'

/**
 * The optional team step of the setup wizard: the users screen's invitation form. An invitation keeps the person here,
 * so another can follow; the way on reads "Continue" once someone has been invited, "Skip for now" until then.
 */
defineProps<{
  roles: RoleOption[]
  /** Someone besides the person setting up has been invited. */
  complete: boolean
  /** Where people can be invited later, worded by the server with the labels of the menu. */
  later: string
  urls: { submit: string; skip: string }
  /** The previous optional step, null when there is none. */
  backUrl: string | null
}>()

const page = usePage<OnboardingPageProps>()
const t = computed(() => page.props.translations.console.onboarding.team)
const step = computed(() => page.props.translations.console.onboarding.steps.team)
const text = computed(() => page.props.translations.console.onboarding)
</script>

<template>
  <Head :title="step.title" />

  <div class="flex flex-col gap-5">
    <div>
      <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>
        <Button as-child variant="outline">
          <Link :href="urls.skip">{{ complete ? text.continue : text.skip }}</Link>
        </Button>
      </div>
      <p class="text-muted-foreground mt-1 text-sm">{{ t.description }}</p>
      <p class="text-muted-foreground mt-1 text-sm">{{ later }}</p>
    </div>

    <InviteForm :roles="roles" :submit-url="urls.submit">
      <template #actions>
        <Button v-if="backUrl" as-child variant="ghost">
          <Link :href="backUrl"><ArrowLeft aria-hidden="true" />{{ text.back }}</Link>
        </Button>
        <Button as-child :variant="complete ? 'default' : 'ghost'">
          <Link :href="urls.skip">{{ complete ? text.continue : text.skip }}</Link>
        </Button>
      </template>
    </InviteForm>
  </div>
</template>
