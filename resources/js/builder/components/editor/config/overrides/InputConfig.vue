<script setup>
const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

const EXPECTED_TYPES = [
    'text', 'number', 'phone', 'email', 'callback',
    'reply', 'contact', 'location', 'document', 'image', 'any',
]

function update(key, value) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div>
        <div class="config-section">
            <div class="config-label">Variable</div>
            <div class="config-field">
                <div class="field-label">Save to</div>
                <input
                    class="field-input"
                    style="font-family:'DM Mono',monospace"
                    :value="props.node.config?.save_to ?? ''"
                    placeholder="flow.variable_name"
                    @input="update('save_to', $event.target.value)"
                >
            </div>
            <div class="config-field">
                <div class="field-label">Expected type</div>
                <select
                    class="field-input"
                    :value="props.node.config?.expected_type ?? 'text'"
                    @change="update('expected_type', $event.target.value)"
                >
                    <option v-for="type in EXPECTED_TYPES" :key="type" :value="type">{{ type }}</option>
                </select>
            </div>
        </div>

        <div class="config-section">
            <div class="config-label">Validation</div>
            <div class="config-field">
                <div class="field-label">Retry limit</div>
                <input
                    class="field-input"
                    type="number"
                    style="width:100px"
                    :value="props.node.config?.retry_limit ?? 3"
                    @input="update('retry_limit', $event.target.value === '' ? null : Number($event.target.value))"
                >
            </div>
            <div class="config-field">
                <div class="field-label">On invalid message</div>
                <input
                    class="field-input"
                    :value="props.node.config?.on_invalid_message ?? ''"
                    placeholder="Please enter a valid value"
                    @input="update('on_invalid_message', $event.target.value)"
                >
            </div>
            <div class="config-field">
                <div class="field-label">Timeout <small>e.g. 24h, 300s</small></div>
                <input
                    class="field-input"
                    :value="props.node.config?.timeout ?? ''"
                    placeholder="24h"
                    @input="update('timeout', $event.target.value)"
                >
            </div>
        </div>

        <div class="config-section">
            <div class="config-label">Meta</div>
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'DM Mono',monospace;font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </div>
    </div>
</template>
