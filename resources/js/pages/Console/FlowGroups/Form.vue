<script setup lang="ts">
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import type { FlowGroupsPageProps } from './types'

/** The fields of a flow group, shared by the create and the edit screen. The server validates; its errors show under the fields. */
const props = defineProps<{
  initial: { name: string }
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
}>()

const page = usePage<FlowGroupsPageProps>()
const t = computed(() => page.props.translations.console.flow_groups)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
})

function submit(): void {
  form.submit(props.method, props.submitUrl, { preserveScroll: true })
}
</script>

<template>
  <form class="flex max-w-xl flex-col gap-5" novalidate @submit.prevent="submit">
    <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
      <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
    </FormField>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
