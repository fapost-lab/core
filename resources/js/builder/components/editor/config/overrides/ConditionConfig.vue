<script setup lang="ts">
import AccordionSection from '../AccordionSection.vue'

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

function update(key: string, value: unknown) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Expression" default-open>
            <div class="config-field">
                <div class="field-label">Condition <small>boolean expression</small></div>
                <input
                    class="field-input"
                    style="font-family:'DM Mono',monospace"
                    :value="props.node.config?.expression ?? ''"
                    placeholder="flow.department == 'hr'"
                    @input="update('expression', ($event.target as HTMLInputElement).value)"
                >
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'DM Mono',monospace;font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>
