<script setup lang="ts">
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import {fieldComponentFor} from '../fieldComponents'
import {useRegistryStore} from '@builder/store/registryStore'
import {isTranslatableField} from '@builder/utils/translatableFields'
import {useBuilderStore} from '@builder/store/builderStore'
import {useTranslations} from '@builder/composables/useTranslations'
import {
    EXPOSABLE_FIELD_TYPES,
    isEmptyParameterValue,
    nextParameterValues,
    withBaseLanguageText,
    parameterValueText,
    readTemplateOutputs,
    readTemplateParameters,
    templateOutputLabel,
} from '@builder/utils/templateNodes'

const emit = defineEmits(['update:config'])

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const {t} = useTranslations()
const builderStore = useBuilderStore()
const registryStore = useRegistryStore()

const config = computed(() => (props.node.config ?? {}) as Record<string, unknown>)
const selectedId = computed(() => (typeof config.value.template_flow_id === 'string' ? config.value.template_flow_id : ''))
const templates = computed(() => builderStore.availableTemplates)
const isMissing = computed(() => selectedId.value !== '' && !templates.value.some((tpl) => tpl.id === selectedId.value))
const outputs = computed(() => readTemplateOutputs(config.value))

const baseLanguage = computed(() => builderStore.contentBaseLanguage)
const selectedTemplate = computed(() => templates.value.find((tpl) => tpl.id === selectedId.value) ?? null)
const parameters = computed(() => readTemplateParameters(config.value))
const parameterValues = computed(() => {
    const raw = config.value.parameter_values
    return raw !== null && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, unknown>) : {}
})
const orphanKeys = computed(() => {
    const known = new Set(parameters.value.map((p) => p.key))
    return Object.keys(parameterValues.value).filter((key) => !known.has(key))
})

/** Controls that show the template's value as a placeholder: an empty input is how the host keeps it. */
const PLACEHOLDER_TYPES = ['string', 'number', 'state-picker']

/**
 * The other exposable controls have no placeholder: with no host value they display the template's value
 * themselves, marked as inherited, instead of an empty or false state that contradicts it. `text` is left out, its
 * hint line stays.
 */
const INHERITING_TYPES = EXPOSABLE_FIELD_TYPES.filter((type) => type !== 'text' && !PLACEHOLDER_TYPES.includes(type))

interface ParameterRow {
    key: string
    label: string
    type: string
    translatable: boolean
    schema: Record<string, unknown>
    templateValue: string
    placeholderInline: boolean
    /** The template's own value as the control takes it; undefined when it is unknown or the control has no way to show it. */
    inheritedValue: unknown
}

const rows = computed<ParameterRow[]>(() => parameters.value.map((p) => {
    const raw = registryStore.getByType(p.node_type, p.node_version)?.config_schema?.[p.field]
    const source = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : null
    // A missing field falls back to a plain text field.
    const type = typeof source?.type === 'string' ? source.type : 'string'
    // visible_when would be evaluated against the reference config and hide the field;
    // default would mask the template's own value.
    const schema: Record<string, unknown> = {...(source ?? {type})}
    delete schema.visible_when
    delete schema.default
    const defaultValue = selectedTemplate.value?.parameters?.find((tp) => tp.key === p.key)?.default
    const templateValue = parameterValueText(defaultValue, baseLanguage.value)
    const placeholderInline = PLACEHOLDER_TYPES.includes(type)
    if (placeholderInline && templateValue !== '') {
        schema.placeholder = templateValue
    }

    const translatable = isTranslatableField(p.node_type, p.field)

    return {
        key: p.key,
        label: p.label ?? p.key,
        type,
        translatable,
        schema,
        templateValue,
        placeholderInline,
        inheritedValue: !translatable && INHERITING_TYPES.includes(type) ? inheritedControlValue(type, defaultValue) : undefined,
    }
}))

function inheritedControlValue(type: string, defaultValue: unknown): unknown {
    if (isEmptyParameterValue(defaultValue)) return undefined
    if (type === 'boolean') return defaultValue === true || defaultValue === 'true' || defaultValue === 1
    if (type === 'array') return Array.isArray(defaultValue) ? defaultValue : undefined

    return typeof defaultValue === 'string' ? defaultValue : undefined
}

/** True when the control shows the template's own value because the host has set none. */
function isInherited(row: ParameterRow): boolean {
    return row.inheritedValue !== undefined && !hasValue(row.key)
}

function controlValue(row: ParameterRow): unknown {
    const stored = parameterValues.value[row.key]
    if (row.translatable) {
        if (stored !== null && typeof stored === 'object') {
            const base = (stored as Record<string, unknown>)[baseLanguage.value]
            return typeof base === 'string' ? base : ''
        }
        return typeof stored === 'string' ? stored : ''
    }
    if (stored === undefined || stored === null) {
        if (row.inheritedValue !== undefined) return row.inheritedValue
        return row.type === 'boolean' ? false : row.type === 'array' ? [] : ''
    }
    return stored
}

function emitValues(next: Record<string, unknown>) {
    emit('update:config', {parameter_values: Object.keys(next).length > 0 ? next : undefined})
}

function setValue(row: ParameterRow, value: unknown) {
    const next = row.translatable
        ? withBaseLanguageText(parameterValues.value[row.key], baseLanguage.value, typeof value === 'string' ? value : '')
        : value
    emit('update:config', {parameter_values: nextParameterValues(parameterValues.value, row.key, next)})
}

function removeValue(key: string) {
    const values = {...parameterValues.value}
    delete values[key]
    emitValues(values)
}

function hasValue(key: string): boolean {
    return !isEmptyParameterValue(parameterValues.value[key])
}

function onChange(event: Event) {
    const id = (event.target as HTMLSelectElement).value
    if (id !== '') {
        builderStore.selectTemplate(props.node.id as string, id)
    }
}
</script>

<template>
    <div class="accordion">
        <AccordionSection :title="t('nodes.template.type_label')" default-open>
            <div class="config-field">
                <div class="field-label">{{ t('nodes.template.template') }}</div>
                <select class="field-input" :value="selectedId" @change="onChange">
                    <option value="" disabled>{{ t('nodes.template.choose') }}</option>
                    <option v-if="isMissing" :value="selectedId" disabled>{{ t('nodes.template.unavailable_option') }}</option>
                    <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">{{ tpl.name }} (v{{ tpl.version }})</option>
                </select>
                <p v-if="templates.length === 0" class="field-help">{{ t('nodes.template.none_published') }}</p>
                <p v-if="isMissing" class="field-help template-config-warn">{{ t('nodes.template.missing') }}</p>
                <p v-else class="field-help">{{ t('nodes.template.help') }}</p>
            </div>

            <div v-if="outputs.length > 0" class="config-field">
                <div class="field-label">{{ t('nodes.template.outputs') }}</div>
                <ul class="template-outputs">
                    <li v-for="o in outputs" :key="o.name">
                        {{ templateOutputLabel(o) }}
                        <code v-if="o.label">{{ o.name }}</code>
                    </li>
                </ul>
            </div>

            <a v-if="selectedId !== ''" class="template-config-open" :href="`/builder/flows/${selectedId}`">
                {{ t('nodes.template.open') }} ↗
            </a>
        </AccordionSection>

        <AccordionSection
            v-if="rows.length > 0 || orphanKeys.length > 0"
            :title="t('nodes.template.parameters')"
            :badge="rows.length"
            default-open
        >
            <p class="field-help">{{ t('nodes.template.parameters_help') }}</p>

            <div v-for="row in rows" :key="row.key" class="config-field">
                <div class="field-label">{{ row.label }}</div>
                <component
                    :is="fieldComponentFor(row.type)"
                    :schema="row.schema"
                    :value="controlValue(row)"
                    @update:value="setValue(row, $event)"
                />
                <p v-if="isInherited(row)" class="field-help">{{ t('nodes.template.inherited') }}</p>
                <p v-else-if="!row.placeholderInline && row.templateValue !== ''" class="field-help">
                    {{ t('nodes.template.template_value') }}: {{ row.templateValue }}
                </p>
                <p v-if="row.translatable" class="field-help">{{ t('nodes.template.parameter_languages') }}</p>
                <button v-if="hasValue(row.key)" type="button" class="template-param-action" @click="removeValue(row.key)">
                    {{ t('nodes.template.reset_parameter') }}
                </button>
            </div>

            <div v-for="key in orphanKeys" :key="`orphan-${key}`" class="config-field">
                <div class="field-label"><code>{{ key }}</code></div>
                <p class="field-help template-config-warn">{{ t('nodes.template.parameter_orphan') }}</p>
                <button type="button" class="template-param-action" @click="removeValue(key)">
                    {{ t('nodes.template.remove_parameter') }}
                </button>
            </div>
        </AccordionSection>

        <AccordionSection :title="t('nodes.branch.meta')">
            <div class="config-field">
                <div class="field-label">{{ t('nodes.branch.node_id') }}</div>
                <input
                    class="field-input"
                    style="font-family:var(--font-mono);font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.template-outputs {
    margin: 0;
    padding-left: 18px;
    font-size: 12.5px;
    color: var(--text);
}
.template-outputs code {
    margin-left: 6px;
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-3);
}
.template-config-warn { color: var(--amber); }
.template-param-action {
    margin-top: 4px;
    padding: 0;
    border: none;
    background: transparent;
    font-size: 11.5px;
    color: var(--primary);
    cursor: pointer;
}
.template-param-action:hover { text-decoration: underline; }
.template-config-open {
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
}
.template-config-open:hover { text-decoration: underline; }
</style>
