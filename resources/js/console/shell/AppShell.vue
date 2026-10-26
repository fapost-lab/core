<script setup lang="ts">
import { computed, inject, provide } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Toaster } from '@fapost/ui/components/sonner'
import { KIT_MESSAGES } from '@fapost/ui/lib/messages'
import AppRail from './AppRail.vue'
import ShellBanners from './ShellBanners.vue'
import ShellHeader from './ShellHeader.vue'
import ShellSidebar from './ShellSidebar.vue'
import { SHELL_SLOT } from './slots'
import { useFlashToasts } from './useFlashToasts'
import { useIsDark } from '@shared/theme/useIsDark'
import type { ShellPageProps } from './types'

/**
 * The frame of every console page: the application rail (the app switcher), the side menu of the current application,
 * the header with breadcrumbs, search and the language, theme and user controls, and the banners above the page. Pages choose it as a persistent layout (`defineOptions({ layout: AppShell })`),
 * so the menu keeps its state while the page changes.
 *
 * Inertia's flash data (`success`, `error`) is shown here as toasts, after every visit; they follow the console's theme.
 *
 * The footer under every page credits the installation (`app.name`) and the platform it runs on.
 *
 * The kit's own strings (DataTable, the close button of dialogs and sheets) come from here too: the shell provides
 * them under KIT_MESSAGES from the console lang files, read on every render, so a language switch reaches them.
 */
const page = usePage<ShellPageProps>()
provide(KIT_MESSAGES, () => ({
  close: page.props.translations.console.close,
  table: page.props.translations.console.table,
}))

useFlashToasts()
const dark = useIsDark()
// What the application adds to the shell's slots (`slots.ts`); nothing without a provider.
const slotted = inject(SHELL_SLOT, null)
const t = computed(() => page.props.translations.console)
const year = new Date().getFullYear()
</script>

<template>
  <div class="bg-background text-foreground flex min-h-dvh">
    <!-- The columns stretch to the row, so their backgrounds reach the bottom of a long page; only their content is sticky. -->
    <div class="bg-rail border-rail-border hidden shrink-0 border-r lg:block [&>div>nav]:border-r-0">
      <div class="sticky top-0 h-dvh">
        <AppRail />
      </div>
    </div>

    <aside class="bg-sidebar text-sidebar-foreground border-sidebar-border hidden w-[236px] shrink-0 border-r lg:block">
      <div class="sticky top-0 h-dvh">
        <ShellSidebar />
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <ShellBanners />
      <ShellHeader />
      <main class="flex-1 px-4 pt-6 pb-10 sm:px-6 lg:px-8 lg:pt-7">
        <div class="w-full max-w-[1200px]">
          <slot />
        </div>
      </main>
      <footer class="text-faint-foreground border-border border-t px-4 py-4 text-xs sm:px-6 lg:px-8">
        <div class="flex w-full max-w-[1200px] flex-wrap items-center justify-between gap-x-4 gap-y-1">
          <span>&copy; {{ year }} {{ page.props.shell.appName }}. {{ t.footer.rights }}</span>
          <span>
            {{ t.footer.powered_by }}
            <a href="https://fapost.in" target="_blank" rel="noopener" class="text-muted-foreground hover:text-foreground font-medium transition-colors">FaPost</a>
            <span class="ms-1 tabular-nums">{{ page.props.shell.version }}</span>
          </span>
        </div>
      </footer>
    </div>

    <component :is="slotted" v-if="slotted" place="floating" />

    <Toaster :theme="dark ? 'dark' : 'light'" position="bottom-right" close-button />
  </div>
</template>
