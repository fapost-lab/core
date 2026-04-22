<script setup>
const props = defineProps({
    node: { type: Object, required: true },
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
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Message text <span class="text-red-400">*</span></label>
            <textarea
                class="w-full rounded border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400 resize-none"
                rows="4"
                :value="props.node.config?.body ?? ''"
                placeholder="Use {{flow.variable}} for dynamic values"
                @input="update('body', $event.target.value)"
            />
            <span
                v-pre
                class="text-xs text-gray-300"
            >Supports {{flow.*}}, {{system.*}} variables</span>
        </div>

        <div class="flex flex-col gap-2">
            <label class="text-xs font-medium text-gray-500">Buttons</label>
            <div
                v-for="(btn, index) in (props.node.config?.buttons ?? [])"
                :key="index"
                class="flex gap-2 items-center"
            >
                <input
                    class="flex-1 rounded border border-gray-200 px-2 py-1 text-sm"
                    :value="btn.label"
                    placeholder="Label"
                    @input="updateButton(index, 'label', $event.target.value)"
                >
                <input
                    class="flex-1 rounded border border-gray-200 px-2 py-1 text-sm"
                    :value="btn.value"
                    placeholder="Value"
                    @input="updateButton(index, 'value', $event.target.value)"
                >
                <button
                    class="text-gray-300 hover:text-red-400 text-xs"
                    type="button"
                    @click="removeButton(index)"
                >x</button>
            </div>
            <button
                class="text-xs text-blue-400 hover:text-blue-500 text-left"
                type="button"
                @click="addButton"
            >
                + Add button
            </button>
        </div>
    </div>
</template>
