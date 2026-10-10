<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft, Download, FileQuestion, Workflow } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { EmptyState } from '@fapost/ui/components/empty-state'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'
import { previewElement } from './folders'
import type { MediaPageProps } from './types'

/**
 * A media file's own page: a preview by type, what the file is, where flows use it, and a download. The preview and the
 * download are a short-lived signed URL; a file in the trash has neither.
 */
const props = defineProps<{
  file: {
    id: string
    name: string
    kind: string
    kindLabel: string
    mimeType: string | null
    size: string
    folder: string | null
    source: string
    createdAt: string | null
    trashed: boolean
  }
  preview: { type: string | null; url: string } | null
  references: { id: string; type: string; name: string; node: string | null }[]
  urls: { index: string }
}>()

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)

const element = computed(() => previewElement(props.preview?.type))

const details = computed(() => [
  { label: t.value.show.kind, value: props.file.kindLabel },
  { label: t.value.show.mime, value: props.file.mimeType ?? '—' },
  { label: t.value.show.size, value: props.file.size },
  { label: t.value.show.folder, value: props.file.folder ?? t.value.root },
  { label: t.value.show.source, value: t.value.sources[props.file.source] ?? props.file.source },
])
</script>

<template>
  <Head :title="file.name" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-col gap-2">
      <Link :href="urls.index" class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1.5 text-sm">
        <ArrowLeft class="size-4" aria-hidden="true" />
        {{ t.show.back }}
      </Link>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
          <h1 class="font-display truncate text-[28px] leading-tight font-semibold">{{ file.name }}</h1>
          <Badge v-if="file.trashed" variant="danger">{{ t.in_trash }}</Badge>
        </div>
        <Button v-if="preview" as-child>
          <a :href="preview.url" :download="file.name">
            <Download aria-hidden="true" />
            {{ t.show.download }}
          </a>
        </Button>
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
      <Card>
        <CardContent class="flex justify-center p-6">
          <p v-if="!preview" class="text-muted-foreground py-12 text-center text-sm">{{ t.show.in_trash }}</p>
          <img v-else-if="element === 'image'" :src="preview.url" :alt="file.name" class="max-h-[70vh] max-w-full rounded-lg" />
          <video v-else-if="element === 'video'" controls preload="metadata" class="max-h-[70vh] max-w-full rounded-lg">
            <source :src="preview.url" />
          </video>
          <audio v-else-if="element === 'audio'" controls preload="metadata" class="w-full max-w-md">
            <source :src="preview.url" />
          </audio>
          <embed v-else-if="element === 'embed'" :src="preview.url" :type="file.mimeType ?? undefined" class="h-[70vh] w-full rounded-lg border" />
          <EmptyState v-else :icon="FileQuestion" :title="file.name" :description="t.show.no_preview" class="py-12" />
        </CardContent>
      </Card>

      <div class="flex flex-col gap-5">
        <Card>
          <CardHeader>
            <CardTitle>{{ t.show.details }}</CardTitle>
          </CardHeader>
          <CardContent>
            <dl class="grid gap-3 text-sm">
              <div v-for="detail in details" :key="detail.label" class="grid gap-0.5">
                <dt class="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{{ detail.label }}</dt>
                <dd class="break-words">{{ detail.value }}</dd>
              </div>
              <div v-if="file.createdAt" class="grid gap-0.5">
                <dt class="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{{ t.show.uploaded }}</dt>
                <dd :title="file.createdAt">{{ relativeTime(file.createdAt, page.props.locale) }}</dd>
              </div>
            </dl>
          </CardContent>
        </Card>

        <Card id="references">
          <CardHeader>
            <CardTitle>{{ t.show.references }}</CardTitle>
            <CardDescription>{{ t.show.references_hint }}</CardDescription>
          </CardHeader>
          <CardContent>
            <ul v-if="references.length > 0" class="flex flex-col gap-2">
              <li v-for="reference in references" :key="reference.id" class="border-border flex items-start gap-2.5 rounded-md border p-3">
                <Workflow class="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <div class="flex min-w-0 flex-col gap-0.5">
                  <span class="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{{ reference.type }}</span>
                  <span class="font-medium break-words">{{ reference.name }}</span>
                  <span v-if="reference.node" class="text-muted-foreground text-[13px]">{{ interpolate(t.show.node, { name: reference.node }) }}</span>
                </div>
              </li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">{{ t.show.references_none }}</p>
          </CardContent>
        </Card>
      </div>
    </div>
  </div>
</template>
