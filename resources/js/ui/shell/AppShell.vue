<script setup lang="ts">
import { Toaster } from '@fapost/ui/components/sonner'
import ShellBanners from './ShellBanners.vue'
import ShellHeader from './ShellHeader.vue'
import ShellSidebar from './ShellSidebar.vue'
import { useFlashToasts } from './useFlashToasts'
import { useIsDark } from './useIsDark'

/**
 * The frame of every console page: side menu, header with breadcrumbs and the language, theme and user controls,
 * and the banners above the page. Pages choose it as a persistent layout (`defineOptions({ layout: AppShell })`),
 * so the menu keeps its state while the page changes.
 *
 * Inertia's flash data (`success`, `error`) is shown here as toasts, after every visit; they follow the console's theme.
 *
 * The `rail` slot, left of the menu, is reserved for the application strip of a later phase; it is empty now.
 */
useFlashToasts()
const dark = useIsDark()
</script>

<template>
  <div class="bg-background text-foreground flex min-h-screen">
    <slot name="rail" />

    <aside class="bg-sidebar text-sidebar-foreground border-sidebar-border sticky top-0 hidden h-screen w-[248px] shrink-0 border-r lg:block">
      <ShellSidebar />
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <ShellBanners />
      <ShellHeader />
      <main class="flex-1 px-4 pt-6 pb-10 sm:px-6 lg:px-8 lg:pt-7">
        <div class="w-full max-w-[1200px]">
          <slot />
        </div>
      </main>
    </div>

    <Toaster :theme="dark ? 'dark' : 'light'" position="bottom-right" close-button />
  </div>
</template>
