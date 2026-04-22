<script setup>
const props = defineProps({
    value: { type: Array, default: () => [] },
    schema: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

function add() {
    emit('update:value', [...props.value, ''])
}

function update(index, nextValue) {
    const updated = [...props.value]
    updated[index] = nextValue
    emit('update:value', updated)
}

function remove(index) {
    emit('update:value', props.value.filter((_, i) => i !== index))
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-for="(item, index) in value"
            :key="index"
            class="flex gap-2"
        >
            <input
                class="flex-1 rounded border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400"
                :value="item"
                @input="update(index, $event.target.value)"
            >
            <button
                class="text-gray-300 hover:text-red-400 text-xs px-2"
                type="button"
                @click="remove(index)"
            >x</button>
        </div>
        <button
            class="text-xs text-blue-400 hover:text-blue-500 text-left"
            type="button"
            @click="add"
        >+ Add item</button>
    </div>
</template>
