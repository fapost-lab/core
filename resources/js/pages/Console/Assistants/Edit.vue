<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Trash2 } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { interpolate } from '@fapost/ui/shell'
import AssistantForm from './Form.vue'
import type { AssistantFields, AssistantsPageProps, LanguageOption } from './types'

const props = defineProps<{
  assistant: AssistantFields & { id: string }
  languages: LanguageOption[]
  can: { delete: boolean }
  urls: { index: string; show: string; submit: string; destroy: string }
}>()

const page = usePage<AssistantsPageProps>()
const t = computed(() => page.props.translations.console.assistants)
const common = computed(() => page.props.translations.console.form)

const deleteOpen = ref(false)

function destroy(): void {
  router.delete(props.urls.destroy)
}
</script>

<template>
  <Head :title="t.edit_title" />

  <div class="flex w-full flex-col gap-5">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.edit_title }}</h1>
      <Button v-if="can.delete" type="button" variant="outline-danger" class="ml-auto" @click="deleteOpen = true">
        <Trash2 aria-hidden="true" />
        {{ common.delete }}
      </Button>
    </div>

    <AssistantForm :key="assistant.id" :initial="assistant" :languages="languages" method="put" :submit-url="urls.submit" :cancel-url="urls.show" />

    <ConfirmDialog
      v-model:open="deleteOpen"
      destructive
      :title="t.delete_one.title"
      :description="interpolate(t.delete_one.description, { name: assistant.name })"
      :confirm-label="common.delete"
      :cancel-label="common.cancel"
      @confirm="destroy"
    />
  </div>
</template>
