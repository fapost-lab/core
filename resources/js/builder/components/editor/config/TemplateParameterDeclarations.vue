<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import AccordionSection from './AccordionSection.vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useTranslations} from '@builder/composables/useTranslations'
import {exposableFields, PARAMETER_KEY_PATTERN} from '@builder/utils/templateNodes'
import type {TemplateParameterDeclaration} from '@builder/dto/types'

/**
 * Template authoring: expose top-level config fields of the selected inner node
 * as parameters of the template. The server validates on publish; the warnings
 * here only surface the obvious mistakes earlier.
 */
const props = defineProps({
    nodeId:   {type: String, required: true},
    nodeType: {type: String, required: true},
    schema:   {type: Object as () => Record<string, unknown>, required: true},
})

const {t} = useTranslations()
const builderStore = useBuilderStore()

const node = computed(() => builderStore.definition.nodes.find((n) => n.id === props.nodeId) ?? null)
const declared = computed<TemplateParameterDeclaration[]>(() => node.value?.parameters ?? [])
const available = computed(() => {
    const taken = new Set(declared.value.map((p) => p.field))
    return exposableFields(props.schema, props.nodeType).filter((f) => !taken.has(f.key))
})
const hasExposable = computed(() => exposableFields(props.schema, props.nodeType).length > 0)

const field = ref('')
const key = ref('')
const label = ref('')

function fieldLabel(fieldKey: string): string {
    return exposableFields(props.schema, props.nodeType).find((f) => f.key === fieldKey)?.label ?? fieldKey
}

watch(field, (value) => {
    key.value = value
    label.value = value === '' ? '' : fieldLabel(value)
})

// A different node was selected: drop the half-filled form.
watch(() => props.nodeId, () => {
    field.value = ''
})

/** Keys declared anywhere in the template, this node included: a key is unique across the whole template. */
function usedKeys(): string[] {
    return builderStore.definition.nodes.flatMap((n) => (n.parameters ?? []).map((p) => p.key))
}

const keyWarning = computed(() => {
    if (key.value === '') return ''
    if (!PARAMETER_KEY_PATTERN.test(key.value)) return t('nodes.template.parameter_key_invalid')
    if (usedKeys().includes(key.value)) return t('nodes.template.parameter_key_taken')
    return ''
})
const canAdd = computed(() => field.value !== '' && key.value !== '' && keyWarning.value === '')

function add() {
    if (!canAdd.value) return
    const trimmed = label.value.trim()
    builderStore.setNodeParameters(props.nodeId, [
        ...declared.value,
        {key: key.value, label: trimmed === '' ? null : trimmed, field: field.value},
    ])
    field.value = ''
}

function remove(paramKey: string) {
    builderStore.setNodeParameters(props.nodeId, declared.value.filter((p) => p.key !== paramKey))
}
</script>

<template>
    <div v-if="hasExposable || declared.length > 0" class="accordion">
        <AccordionSection :title="t('nodes.template.exposed_fields')" :badge="declared.length || null">
            <p class="field-help">{{ t('nodes.template.exposed_fields_help') }}</p>

            <ul v-if="declared.length > 0" class="param-list">
                <li v-for="p in declared" :key="p.key" class="param-item">
                    <span class="param-text">
                        <strong>{{ p.label ?? p.key }}</strong>
                        <code>{{ p.key }}</code>
                        <span class="param-field">{{ fieldLabel(p.field) }}</span>
                    </span>
                    <button type="button" class="param-remove" :title="t('nodes.template.remove_parameter')" @click="remove(p.key)">×</button>
                </li>
            </ul>

            <template v-if="available.length > 0">
                <div class="config-field">
                    <div class="field-label">{{ t('nodes.template.parameter_field') }}</div>
                    <select v-model="field" class="field-input">
                        <option value="" disabled>{{ t('nodes.template.parameter_choose_field') }}</option>
                        <option v-for="f in available" :key="f.key" :value="f.key">{{ f.label }}</option>
                    </select>
                </div>
                <template v-if="field !== ''">
                    <div class="config-field">
                        <div class="field-label">{{ t('nodes.template.parameter_key') }}</div>
                        <input v-model="key" class="field-input param-key" :class="{'field-input--error': keyWarning !== ''}" type="text">
                        <p v-if="keyWarning" class="param-warn">{{ keyWarning }}</p>
                    </div>
                    <div class="config-field">
                        <div class="field-label">{{ t('nodes.template.parameter_label') }}</div>
                        <input v-model="label" class="field-input" type="text">
                    </div>
                    <button type="button" class="param-add" :disabled="!canAdd" @click="add">
                        {{ t('nodes.template.add_parameter') }}
                    </button>
                </template>
            </template>
        </AccordionSection>
    </div>
</template>

<style scoped>
.param-list { margin: 0 0 10px; padding: 0; list-style: none; }
.param-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 5px 0;
    border-bottom: 1px solid var(--border);
    font-size: 12.5px;
    color: var(--text);
}
.param-text { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px; min-width: 0; }
.param-text code { font-family: var(--font-mono); font-size: 11px; color: var(--text-3); }
.param-field { font-size: 11px; color: var(--text-3); }
.param-remove {
    border: none;
    background: transparent;
    font-size: 16px;
    line-height: 1;
    color: var(--text-3);
    cursor: pointer;
}
.param-remove:hover { color: var(--rose); }
.param-key { font-family: var(--font-mono); font-size: 11.5px; }
.field-input--error { border-color: var(--rose); }
.param-warn { margin: 3px 0 0; font-size: 11px; color: var(--rose); line-height: 1.35; }
.param-add {
    padding: 5px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-size: 12px;
    color: var(--primary);
    cursor: pointer;
}
.param-add:disabled { opacity: .5; cursor: default; }
</style>
