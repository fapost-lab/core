<script setup lang="ts">
import {computed} from 'vue'
import AccordionSection from './AccordionSection.vue'
import SchemaFields from './SchemaFields.vue'
import {type SchemaSection, schemaSections} from './schemaSections'

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

// Declared `sections` resolved to their fields; without them, one "Configuration"
// section holds every field (legacy generic-renderer behaviour).
const sections = computed<SchemaSection[]>(() =>
    schemaSections(props.schema as Record<string, unknown>, {key: 'configuration', label: 'Configuration'}),
)

const rootConfig = computed<Record<string, unknown>>(() =>
    (props.node?.config ?? {}) as Record<string, unknown>,
)

function emitPatch(patch: Record<string, unknown>) {
    emit('update:config', patch)
}
</script>

<template>
    <div class="accordion">
        <AccordionSection
            v-for="section in sections"
            :key="section.key"
            :title="section.label"
            :icon="section.icon"
            :default-open="!section.collapsed"
        >
            <SchemaFields
                :config="rootConfig"
                :fields="section.fields"
                :root-config="rootConfig"
                @update:config="emitPatch"
            />
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:var(--font-mono);font-size:11.5px"
                    :value="node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>
