<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { FormField } from '@fapost/ui/components/form-field'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@fapost/ui/components/select'
import LocalizedMessageField from './LocalizedMessageField.vue'
import ReachLine from './ReachLine.vue'
import TagsPicker from './TagsPicker.vue'
import { cleanMessage } from './localized'
import { useReach } from './useReach'
import { interpolate } from '@fapost/ui/shell'
import type { BroadcastFormState, BroadcastLanguage, BroadcastTarget, BroadcastsPageProps, SelectOption } from './types'

/**
 * The fields of a broadcast, shared by the create and the edit screen. There is no send button: a draft is sent from
 * the list, from a confirmation that shows what is about to go out. The server validates; its errors show under the
 * fields.
 */
const props = defineProps<{
  initial: BroadcastFormState
  languages: BroadcastLanguage[]
  options: { tags: string[]; segments: SelectOption[] }
  method: 'post' | 'put'
  submitUrl: string
  cancelUrl: string
  reachUrl: string
}>()

const TARGETS: BroadcastTarget[] = ['all', 'tags', 'segment']

const page = usePage<BroadcastsPageProps>()
const t = computed(() => page.props.translations.console.broadcasts)
const common = computed(() => page.props.translations.console.form)

const form = useForm({
  name: props.initial.name,
  message: props.initial.message,
  target_type: props.initial.targetType,
  target_tags: props.initial.targetTags,
  target_segment_id: props.initial.targetSegmentId,
})

// What is sent: only the languages with text.
form.transform((data) => ({ ...data, message: cleanMessage(data.message) }))

const errors = computed(() => form.errors as Record<string, string | undefined>)

const reach = useReach(
  () => props.reachUrl,
  () => ({ targetType: form.target_type, targetTags: form.target_tags, targetSegmentId: form.target_segment_id }),
)

onMounted(reach.refresh)

const segmentValue = computed({
  get: () => form.target_segment_id ?? undefined,
  set: (value: string | undefined) => {
    form.target_segment_id = value ?? null
  },
})

function submit(): void {
  form.submit(props.method, props.submitUrl, { preserveScroll: true })
}
</script>

<template>
  <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
    <FormSection :title="t.sections.message.title" :description="t.sections.message.description">
      <FormField id="name" :label="t.fields.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
        <Input id="name" v-model="form.name" name="name" required maxlength="255" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
      </FormField>

      <FormField id="message" :label="t.fields.message" :hint="t.fields.message_help" :error="form.errors.message">
        <LocalizedMessageField id="message" v-model="form.message" :languages="languages" :errors="errors" :required-label="t.fields.base_required" />
      </FormField>
    </FormSection>

    <FormSection :title="t.sections.audience.title" :description="t.sections.audience.description">
      <FormField id="target_type" :label="t.fields.target" :error="form.errors.target_type" v-slot="{ invalid, describedBy }">
        <Select v-model="form.target_type">
          <SelectTrigger id="target_type" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="target in TARGETS" :key="target" :value="target">{{ t.targets[target] }}</SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField v-if="form.target_type === 'tags'" id="target_tags" :label="t.fields.tags" :error="errors.target_tags ?? errors['target_tags.0']" v-slot="{ invalid, describedBy }">
        <TagsPicker
          id="target_tags"
          v-model="form.target_tags"
          :options="options.tags"
          :placeholder="t.fields.tags_placeholder"
          :empty-label="t.fields.tags_empty"
          :selected-label="(count) => interpolate(t.fields.tags_selected, { count })"
          :invalid="invalid"
          :described-by="describedBy"
        />
      </FormField>

      <FormField
        v-if="form.target_type === 'segment'"
        id="target_segment_id"
        :label="t.fields.segment"
        :hint="initial.segmentMissing && form.target_segment_id === null ? t.fields.segment_missing : undefined"
        :error="form.errors.target_segment_id"
        v-slot="{ invalid, describedBy }"
      >
        <Select v-model="segmentValue">
          <SelectTrigger id="target_segment_id" class="w-full" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue :placeholder="t.fields.segment_placeholder" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem v-for="segment in options.segments" :key="segment.value" :value="segment.value">{{ segment.label }}</SelectItem>
          </SelectContent>
        </Select>
      </FormField>

      <FormField id="reach" :label="t.fields.reach" :hint="t.reach.note">
        <ReachLine :state="reach.state.value" :count="reach.count.value" :message="reach.message.value" />
      </FormField>
    </FormSection>

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
      <Button as-child variant="ghost">
        <Link :href="cancelUrl">{{ common.cancel }}</Link>
      </Button>
    </div>
  </form>
</template>
