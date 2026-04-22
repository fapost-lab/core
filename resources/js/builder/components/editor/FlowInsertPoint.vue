<script setup>
import { ref } from 'vue'
import NodePalette from './NodePalette.vue'

defineProps({
    afterNodeId: { type: String, default: null },
    handle:      { type: String, default: 'default' },
    index: { type: Number, default: 0 },
})

const open = ref(false)

function toggle() {
    open.value = !open.value
}

function close() {
    open.value = false
}
</script>

<template>
    <div class="relative flex items-center justify-center w-96 py-1">
        <button
            class="flex items-center gap-2 text-gray-300 hover:text-blue-400 transition-colors text-xs px-3 py-1 rounded hover:bg-blue-50"
            @click="toggle"
        >
            <span>+</span>
            <span>Add block</span>
        </button>

        <NodePalette
            v-if="open"
            :after-node-id="afterNodeId"
            :handle="handle"
            @select="close"
            @close="close"
        />
    </div>
</template>
