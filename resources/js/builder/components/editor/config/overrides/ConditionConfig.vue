<script setup>
const props = defineProps({
    node: { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

function update(key, value) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-gray-500">Expression <span class="text-red-400">*</span></label>
            <input
                class="field font-mono text-xs"
                :value="props.node.config?.expression ?? ''"
                placeholder="flow.department == 'hr'"
                @input="update('expression', $event.target.value)"
            >
            <span class="text-xs text-gray-300">Supports flow.*, rag.*, module.* namespaces</span>
        </div>
    </div>
</template>
