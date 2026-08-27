<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import TextareaField from '../fields/TextareaField.vue'
import SearchSelect from '../SearchSelect.vue'
import {useKnownTags} from '@builder/composables/useKnownTags'
import {fetchAssistantOptions, fetchStaffOptions, type SelectOption} from '@builder/api/builderApi'

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const emit = defineEmits(['update:config'])

// ── Remote option sources (loaded once on mount) ──────────────────────────
const staffOptions     = ref<SelectOption[]>([])
const assistantOptions = ref<SelectOption[]>([])
const knownTags        = useKnownTags()
const tagOptions = computed<SelectOption[]>(() => knownTags.value.map((t) => ({value: t, label: t})))

onMounted(async () => {
    staffOptions.value     = await fetchStaffOptions().catch(() => [])
    assistantOptions.value = await fetchAssistantOptions().catch(() => [])
})

// ── Schema-driven (localized) option maps ─────────────────────────────────
function pairs(key: string): SelectOption[] {
    const raw = (props.schema[key] as { options?: unknown } | undefined)?.options
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
        return Object.entries(raw as Record<string, string>).map(([value, label]) => ({value, label}))
    }
    return []
}
const modeOptions          = computed(() => pairs('mode'))
const targetOptions        = computed(() => pairs('target'))
const roleOptions          = computed(() => pairs('role'))
const channelOptions       = computed(() => pairs('channel'))
const contactTargetOptions = computed(() => pairs('contact_target'))

function label(key: string, fallback: string): string {
    return (props.schema[key] as { label?: string })?.label ?? fallback
}

// ── Local config state ────────────────────────────────────────────────────
const cfg = () => (props.node.config ?? {}) as Record<string, unknown>
const str = (v: unknown, d: string): string => (typeof v === 'string' && v !== '' ? v : d)
const arr = (v: unknown): string[] => (Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : [])

const mode          = ref(str(cfg().mode, 'staff'))
const target        = ref(str(cfg().target, 'assistant'))
const role          = ref(str(cfg().role, ''))
const userIds       = ref<string[]>(arr(cfg().user_ids))
const channel       = ref(str(cfg().channel, 'in_app'))
const assistantId   = ref<string[]>(cfg().assistant_id ? [String(cfg().assistant_id)] : [])
const contactTarget = ref(str(cfg().contact_target, 'tag'))
const tags          = ref<string[]>(arr(cfg().tags))
const message       = ref(str(cfg().message, ''))

watch(
    () => props.node.id,
    () => {
        const c = cfg()
        mode.value          = str(c.mode, 'staff')
        target.value        = str(c.target, 'assistant')
        role.value          = str(c.role, '')
        userIds.value       = arr(c.user_ids)
        channel.value       = str(c.channel, 'in_app')
        assistantId.value   = c.assistant_id ? [String(c.assistant_id)] : []
        contactTarget.value = str(c.contact_target, 'tag')
        tags.value          = arr(c.tags)
        message.value       = str(c.message, '')
    },
)

function persist() {
    emit('update:config', {
        mode:           mode.value,
        target:         target.value,
        role:           role.value,
        user_ids:       userIds.value,
        channel:        channel.value,
        assistant_id:   assistantId.value[0] ?? null,
        contact_target: contactTarget.value,
        tags:           tags.value,
        message:        message.value,
    })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection :title="label('mode', 'Mode')" default-open>
            <div class="config-field">
                <div class="field-label">{{ label('mode', 'Notify') }}</div>
                <select class="field-input" :value="mode" @change="(e: Event) => { mode = (e.target as HTMLSelectElement).value; persist() }">
                    <option v-for="o in modeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
        </AccordionSection>

        <!-- ── Staff mode ── -->
        <AccordionSection v-if="mode === 'staff'" :title="label('target', 'Staff recipients')" default-open>
            <div class="config-field">
                <div class="field-label">{{ label('target', 'Recipients') }}</div>
                <select class="field-input" :value="target" @change="(e: Event) => { target = (e.target as HTMLSelectElement).value; persist() }">
                    <option v-for="o in targetOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>

            <div v-if="target === 'role'" class="config-field">
                <div class="field-label">{{ label('role', 'Role') }}</div>
                <select class="field-input" :value="role" @change="(e: Event) => { role = (e.target as HTMLSelectElement).value; persist() }">
                    <option value="">—</option>
                    <option v-for="o in roleOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>

            <div v-if="target === 'users'" class="config-field">
                <div class="field-label">{{ label('user_ids', 'Users') }}</div>
                <SearchSelect
                    :model-value="userIds"
                    :options="staffOptions"
                    multiple
                    empty-text="No staff"
                    @update:model-value="(v: string[]) => { userIds = v; persist() }"
                />
            </div>

            <div class="config-field">
                <div class="field-label">{{ label('channel', 'Channel') }}</div>
                <select class="field-input" :value="channel" @change="(e: Event) => { channel = (e.target as HTMLSelectElement).value; persist() }">
                    <option v-for="o in channelOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>
        </AccordionSection>

        <!-- ── Contacts mode ── -->
        <AccordionSection v-if="mode === 'contacts'" :title="label('assistant', 'Contact recipients')" default-open>
            <div class="config-field">
                <div class="field-label">{{ label('assistant', 'Assistant') }}</div>
                <SearchSelect
                    :model-value="assistantId"
                    :options="assistantOptions"
                    empty-text="No assistants"
                    @update:model-value="(v: string[]) => { assistantId = v; persist() }"
                />
            </div>

            <div class="config-field">
                <div class="field-label">{{ label('contact_target', 'Audience') }}</div>
                <select class="field-input" :value="contactTarget" @change="(e: Event) => { contactTarget = (e.target as HTMLSelectElement).value; persist() }">
                    <option v-for="o in contactTargetOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
            </div>

            <div v-if="contactTarget === 'tag'" class="config-field">
                <div class="field-label">{{ label('tags', 'Tags') }}</div>
                <SearchSelect
                    :model-value="tags"
                    :options="tagOptions"
                    multiple
                    allow-create
                    empty-text="No tags yet"
                    @update:model-value="(v: string[]) => { tags = v; persist() }"
                />
            </div>
        </AccordionSection>

        <AccordionSection :title="label('message', 'Message')" default-open>
            <div class="config-field">
                <div class="field-label">{{ label('message', 'Notification text') }}</div>
                <TextareaField
                    :value="message"
                    :schema="{ placeholder: '{{flow.input}}' }"
                    @update:value="(next: string) => { message = next; persist() }"
                />
            </div>
        </AccordionSection>
    </div>
</template>
