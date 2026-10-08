<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { interpolate } from './nav'
import type { ShellPageProps } from './types'

/** The strips above the page: a platform-support session, and the notice the tenant's access mode carries. */
const page = usePage<ShellPageProps>()

const t = computed(() => page.props.translations.console)
const support = computed(() => page.props.supportAccess)
const notice = computed(() => page.props.accessState.notice)
const stopped = computed(() => page.props.accessState.mode === 'stopped')
</script>

<template>
  <div v-if="support" role="status" class="bg-warning text-warning-foreground flex flex-wrap items-center justify-center gap-3 px-4 py-2 text-sm">
    <span>{{ interpolate(t.support.banner, { name: support.operatorName, email: support.operatorEmail }) }}</span>
    <!-- A plain form post: leaving ends the session and lands on the sign-in page, a full page load. -->
    <form method="POST" :action="page.props.shell.supportLeaveUrl" class="m-0">
      <input type="hidden" name="_token" :value="page.props.csrf_token" />
      <button type="submit" class="rounded border border-current px-2.5 py-0.5 text-sm hover:opacity-80">
        {{ t.support.leave }}
      </button>
    </form>
  </div>

  <div
    v-if="notice"
    role="status"
    :class="[
      'flex flex-wrap items-center justify-center gap-3 px-4 py-2 text-sm',
      stopped ? 'bg-danger text-danger-foreground' : 'bg-info text-info-foreground',
    ]"
  >
    <span class="font-medium">{{ notice.title }}</span>
    <span v-if="notice.message">{{ notice.message }}</span>
    <a v-if="notice.actionUrl && notice.actionLabel" :href="notice.actionUrl" class="underline underline-offset-4">
      {{ notice.actionLabel }}
    </a>
  </div>
</template>
