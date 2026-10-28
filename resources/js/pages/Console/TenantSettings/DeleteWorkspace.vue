<script setup lang="ts">
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { formatUtc, interpolate } from '@console/shell'
import type { TenantSettingsPageProps, WorkspaceDeletionSection } from './types'

/**
 * The danger section: deleting the whole workspace. Where it may be asked for, the admin types the workspace's slug and
 * the deletion is scheduled 24 hours ahead; while one is scheduled the section shows when it starts and who asked,
 * with a button to cancel. The server checks the right to ask and the slug again.
 */
const props = defineProps<{ section: WorkspaceDeletionSection }>()

const page = usePage<TenantSettingsPageProps>()
const t = computed(() => page.props.translations.console.tenant_settings.delete)

const form = useForm({ slug: '' })
const cancel = useForm({})
const confirmed = computed(() => form.slug.trim() === props.section.slug)

function request(): void {
  if (!confirmed.value) {
    return
  }

  form.post(props.section.requestUrl, { preserveScroll: true, onSuccess: () => form.reset() })
}

function cancelDeletion(): void {
  cancel.post(props.section.cancelUrl, { preserveScroll: true })
}
</script>

<template>
  <div data-testid="delete-workspace">
    <FormSection v-if="section.scheduled" :title="t.title" :description="t.scheduled_description">
      <div class="border-danger-border bg-danger text-danger-foreground rounded-md border p-4 text-sm" role="status">
        <p class="font-semibold">
          {{ interpolate(t.scheduled_title, { time: formatUtc(section.scheduled.startsAt, page.props.locale) }) }}
        </p>
        <p class="mt-1">
          {{ interpolate(t.scheduled_by, { name: section.scheduled.requestedBy, time: formatUtc(section.scheduled.requestedAt, page.props.locale) }) }}
        </p>
        <p class="mt-1">{{ t.warning }}</p>
      </div>

      <div>
        <Button type="button" variant="outline" :disabled="cancel.processing" @click="cancelDeletion">
          {{ cancel.processing ? t.cancelling : t.cancel }}
        </Button>
      </div>
    </FormSection>

    <form v-else novalidate @submit.prevent="request">
      <FormSection :title="t.title" :description="t.description">
        <div class="border-danger-border bg-danger text-danger-foreground rounded-md border p-4 text-sm" role="alert">
          <p class="font-semibold">{{ t.warning_title }}</p>
          <p class="mt-1">{{ t.warning }}</p>
          <p class="mt-1">{{ t.delay_notice }}</p>
        </div>

        <FormField
          id="delete_workspace_slug"
          :label="interpolate(t.confirm_label, { slug: section.slug })"
          :hint="t.confirm_hint"
          :error="form.errors.slug"
          v-slot="{ invalid, describedBy }"
        >
          <Input
            id="delete_workspace_slug"
            v-model="form.slug"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            :placeholder="section.slug"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
          />
        </FormField>

        <div>
          <Button type="submit" variant="destructive" :disabled="!confirmed || form.processing">
            {{ form.processing ? t.deleting : t.submit }}
          </Button>
        </div>
      </FormSection>
    </form>
  </div>
</template>
