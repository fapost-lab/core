<script setup lang="ts">
import { computed, provide } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Check } from '@lucide/vue'
import { Toaster } from '@fapost/ui/components/sonner'
import { KIT_MESSAGES } from '@fapost/ui/lib/messages'
import { interpolate } from '@fapost/ui/lib/utils'
import LanguageMenu from '../shell/LanguageMenu.vue'
import ShellBanners from '../shell/ShellBanners.vue'
import ThemeToggle from '../shell/ThemeToggle.vue'
import UserMenu from '../shell/UserMenu.vue'
import { useFlashToasts } from '../shell/useFlashToasts'
import { useIsDark } from '@shared/theme/useIsDark'
import type { OnboardingPageProps } from './types'

/**
 * The frame of the first-run setup wizard: no menu, no rail, only the product name, the progress through the steps,
 * and the language, theme and sign-out controls. The wizard's pages choose it as their layout.
 */
const page = usePage<OnboardingPageProps>()
// The kit's own strings come from the console lang files, as in the shell.
provide(KIT_MESSAGES, () => ({
  close: page.props.translations.console.close,
  table: page.props.translations.console.table,
}))

useFlashToasts()
const dark = useIsDark()

const t = computed(() => page.props.translations.console)
const text = computed(() => t.value.onboarding)
const steps = computed(() => page.props.onboarding?.steps ?? [])
const position = computed(() => Math.max(1, steps.value.findIndex((step) => step.current) + 1))
const year = new Date().getFullYear()
</script>

<template>
  <div class="bg-background text-foreground flex min-h-dvh flex-col">
    <ShellBanners />

    <header class="bg-card/70 flex min-h-14 shrink-0 items-center gap-3 border-b px-4 backdrop-blur sm:px-6 lg:px-8">
      <span class="font-display min-w-0 flex-1 truncate text-lg font-semibold">{{ page.props.shell.appName }}</span>
      <div class="flex items-center gap-1">
        <LanguageMenu :url="page.props.shell.localeUrl" :current="page.props.locale" :locales="page.props.shell.locales" :labels="t.language" />
        <ThemeToggle :labels="t.theme" />
        <UserMenu v-if="page.props.auth.user" :user="page.props.auth.user" :logout-url="page.props.shell.logoutUrl" :labels="t.user_menu" />
      </div>
    </header>

    <main class="flex flex-1 justify-center px-4 py-8 sm:px-6 lg:py-12">
      <div class="flex w-full max-w-[720px] flex-col gap-8">
        <nav v-if="steps.length > 0" :aria-label="text.layout.progress">
          <p class="text-muted-foreground mb-3 text-sm">{{ interpolate(text.layout.step_of, { current: position, total: steps.length }) }}</p>
          <ol class="flex flex-wrap gap-x-6 gap-y-3">
            <li v-for="step in steps" :key="step.key" class="flex items-center gap-2" :aria-current="step.current ? 'step' : undefined">
              <span
                :class="[
                  'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium',
                  step.complete ? 'bg-primary text-primary-foreground border-primary' : step.current ? 'border-primary text-primary' : 'text-muted-foreground',
                ]"
              >
                <Check v-if="step.complete" class="size-3.5" aria-hidden="true" />
                <span v-else aria-hidden="true">{{ steps.indexOf(step) + 1 }}</span>
              </span>
              <span :class="['text-sm', step.current ? 'font-medium' : 'text-muted-foreground']">{{ text.steps[step.key]?.title ?? step.key }}</span>
              <span v-if="step.complete" class="sr-only">({{ text.layout.completed }})</span>
              <span v-else-if="step.current" class="sr-only">({{ text.layout.current }})</span>
              <span v-if="!step.required" class="text-faint-foreground text-xs">{{ text.layout.optional }}</span>
            </li>
          </ol>
        </nav>

        <slot />
      </div>
    </main>

    <footer class="text-faint-foreground border-border border-t px-4 py-4 text-xs sm:px-6 lg:px-8">
      &copy; {{ year }} {{ page.props.shell.appName }}. {{ t.footer.rights }}
    </footer>

    <Toaster :theme="dark ? 'dark' : 'light'" position="bottom-right" close-button />
  </div>
</template>
