<script setup lang="ts">
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useTranslations} from '@builder/composables/useTranslations'
import {readTemplateOutputs, templateOutputLabel} from '@builder/utils/templateNodes'

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const {t} = useTranslations()
const builderStore = useBuilderStore()

const config = computed(() => (props.node.config ?? {}) as Record<string, unknown>)
const selectedId = computed(() => (typeof config.value.template_flow_id === 'string' ? config.value.template_flow_id : ''))
const templates = computed(() => builderStore.availableTemplates)
const isMissing = computed(() => selectedId.value !== '' && !templates.value.some((tpl) => tpl.id === selectedId.value))
const outputs = computed(() => readTemplateOutputs(config.value))

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
.template-config-open {
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
}
.template-config-open:hover { text-decoration: underline; }
</style>
