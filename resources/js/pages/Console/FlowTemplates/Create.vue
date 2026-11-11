<script lang="ts">
import { AppShell } from '@console/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Textarea } from '@fapost/ui/components/textarea'
import type { FlowTemplatesPageProps } from './types'

const props = defineProps<{
  urls: { index: string; submit: string }
}>()

const page = usePage<FlowTemplatesPageProps>()
const t = computed(() => page.props.translations.console.flow_templates)
const common = computed(() => page.props.translations.console.form)

const form = useForm({ name: '', description: '' })

// The server validates; its errors show under the fields. Success ends in the builder, a full navigation.
function submit(): void {
  form.post(props.urls.submit, { preserveScroll: true })
}
</script>

<template>
  <Head :title="t.create_title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.create_title }}</h1>

    <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <FormSection :title="t.sections.general.title" :description="t.sections.general.description">
        <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <FormField id="description" :label="t.fields.description" :error="form.errors.description" v-slot="{ invalid, describedBy }">
          <Textarea id="description" v-model="form.description" name="description" rows="3" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>
      </FormSection>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : t.create_and_open }}</Button>
        <Button as-child variant="ghost">
          <Link :href="urls.index">{{ common.cancel }}</Link>
        </Button>
      </div>
    </form>
  </div>
</template>
