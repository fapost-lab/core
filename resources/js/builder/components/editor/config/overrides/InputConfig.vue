<script setup>
const props = defineProps({
    node: { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

const EXPECTED_TYPES = [
    'text',
    'number',
    'phone',
    'email',
    'callback',
    'reply',
    'contact',
    'location',
    'document',
    'image',
    'any',
]

function update(key, value) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Label <span class="text-red-400">*</span></label>
            <input
                class="field"
                :value="props.node.config?.label ?? ''"
                @input="update('label', $event.target.value)"
            >
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Save to <span class="text-red-400">*</span></label>
            <input
                class="field font-mono text-xs"
                :value="props.node.config?.save_to ?? ''"
                placeholder="flow.variable_name"
                @input="update('save_to', $event.target.value)"
            >
            <span class="text-xs text-gray-300">Use flow.* namespace</span>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Expected type</label>
            <select
                class="field"
                :value="props.node.config?.expected_type ?? EXPECTED_TYPES[0]"
                @change="update('expected_type', $event.target.value)"
            >
                <option
                    v-for="type in EXPECTED_TYPES"
                    :key="type"
                    :value="type"
                >
                    {{ type }}
                </option>
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Timeout</label>
            <input
                class="field"
                type="text"
                :value="props.node.config?.timeout ?? ''"
                placeholder="24h"
                @input="update('timeout', $event.target.value)"
            >
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Retry limit</label>
            <input
                class="field"
                type="number"
                :value="props.node.config?.retry_limit ?? 3"
                @input="update('retry_limit', $event.target.value === '' ? null : Number($event.target.value))"
            >
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">On invalid message</label>
            <input
                class="field"
                :value="props.node.config?.on_invalid_message ?? ''"
                placeholder="Please enter a valid value"
                @input="update('on_invalid_message', $event.target.value)"
            >
        </div>
    </div>
</template>
