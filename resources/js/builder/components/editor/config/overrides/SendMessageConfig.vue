<script setup>
const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

function update(key, value) {
    emit('update:config', { [key]: value })
}

function updateButton(index, key, value) {
    const buttons = [...(props.node.config?.buttons ?? [])]
    buttons[index] = { ...buttons[index], [key]: value }
    emit('update:config', { buttons })
}

function addButton() {
    emit('update:config', {
        buttons: [...(props.node.config?.buttons ?? []), { label: '', value: '' }],
    })
}

function removeButton(index) {
    emit('update:config', {
        buttons: (props.node.config?.buttons ?? []).filter((_, i) => i !== index),
    })
}
</script>

<template>
    <div>
        <div class="config-section">
            <div class="config-label">Body</div>
            <div class="config-field">
                <div class="field-label">Message text</div>
                <textarea
                    class="field-input"
                    rows="4"
                    :value="props.node.config?.body ?? ''"
                    placeholder="Use {{flow.variable}} for dynamic values"
                    @input="update('body', $event.target.value)"
                />
            </div>
        </div>

        <div class="config-section">
            <div class="config-label">Buttons</div>
            <div
                v-for="(btn, index) in (props.node.config?.buttons ?? [])"
                :key="index"
                class="repeater-item"
            >
                <span class="drag-handle">⠿</span>
                <input
                    class="rep-text field-input"
                    style="margin:0;padding:2px 6px"
                    :value="btn.label"
                    placeholder="Label"
                    @input="updateButton(index, 'label', $event.target.value)"
                >
                <input
                    class="rep-text field-input"
                    style="margin:0;padding:2px 6px"
                    :value="btn.value"
                    placeholder="Value"
                    @input="updateButton(index, 'value', $event.target.value)"
                >
                <span class="rep-del" @click="removeButton(index)">×</span>
            </div>
            <button class="add-item-btn" style="margin-top:4px" type="button" @click="addButton">
                + Add button
            </button>
        </div>
    </div>
</template>
