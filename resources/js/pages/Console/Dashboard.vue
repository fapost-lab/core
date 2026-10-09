<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { FileText, ListOrdered } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { interpolate } from '@fapost/ui/shell'

interface DashboardTranslations {
  title: string
  sections: { summary: string; channels: string; operations: string }
  name: string
  status: string
  status_active: string
  status_inactive: string
  channels_intro: string
  operations_intro: string
  live_sessions: string
  errors_24h: string
}

const props = defineProps<{
  assistant: { id: string; name: string; isActive: boolean; channelsCount: number }
  operations: { liveSessions: number; errors24h: number; sessionsUrl: string | null; logsUrl: string | null }
}>()

const page = usePage<{ translations: { console: { dashboard: DashboardTranslations } } }>()
const t = computed(() => page.props.translations.console.dashboard)
const status = computed(() => (props.assistant.isActive ? t.value.status_active : t.value.status_inactive))
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>

    <div class="grid gap-4 md:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle class="font-display tracking-wide uppercase">{{ t.sections.summary }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-2 text-sm">
          <p>{{ interpolate(t.name, { name: assistant.name }) }}</p>
          <p>{{ interpolate(t.status, { active: status }) }}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle class="font-display tracking-wide uppercase">{{ t.sections.channels }}</CardTitle>
          <CardDescription>{{ interpolate(t.channels_intro, { count: assistant.channelsCount }) }}</CardDescription>
        </CardHeader>
      </Card>

      <Card class="md:col-span-2">
        <CardHeader>
          <CardTitle class="font-display tracking-wide uppercase">{{ t.sections.operations }}</CardTitle>
          <CardDescription>{{ t.operations_intro }}</CardDescription>
        </CardHeader>
        <CardContent v-if="!operations.sessionsUrl || !operations.logsUrl" class="text-muted-foreground flex flex-col gap-1 text-sm">
          <p v-if="!operations.sessionsUrl">{{ interpolate(t.live_sessions, { count: operations.liveSessions }) }}</p>
          <p v-if="!operations.logsUrl">{{ interpolate(t.errors_24h, { count: operations.errors24h }) }}</p>
        </CardContent>
        <CardFooter class="flex flex-wrap gap-2">
          <!-- These lists are still Filament's: plain links, a full page load. -->
          <Button v-if="operations.sessionsUrl" as-child variant="outline">
            <a :href="operations.sessionsUrl">
              <ListOrdered aria-hidden="true" />
              {{ interpolate(t.live_sessions, { count: operations.liveSessions }) }}
            </a>
          </Button>
          <Button v-if="operations.logsUrl" as-child variant="outline">
            <a :href="operations.logsUrl">
              <FileText aria-hidden="true" />
              {{ interpolate(t.errors_24h, { count: operations.errors24h }) }}
            </a>
          </Button>
        </CardFooter>
      </Card>
    </div>
  </div>
</template>
