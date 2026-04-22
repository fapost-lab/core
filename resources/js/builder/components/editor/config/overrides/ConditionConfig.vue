<script setup>
const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

function update(key, value) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div>
        <div class="config-section">
            <div class="config-label">Expression</div>
            <div class="config-field">
                <div class="field-label">Condition <small>boolean expression</small></div>
                <input
                    class="field-input"
                    style="font-family:'DM Mono',monospace"
                    :value="props.node.config?.expression ?? ''"
                    placeholder="flow.department == 'hr'"
                    @input="update('expression', $event.target.value)"
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
