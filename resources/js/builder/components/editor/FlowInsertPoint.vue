<script setup>
import { ref } from 'vue'
import NodePalette from './NodePalette.vue'

const props = defineProps({
    afterNodeId: { type: String, default: null },
    handle:      { type: String, default: 'default' },
    index:       { type: Number, default: 0 },
    visible:     { type: Boolean, default: false },
})

const open      = ref(false)
const btnRef    = ref(null)
const palettePos = ref({ top: 0, left: 0 })

function toggle() {
    if (!open.value && btnRef.value) {
        const rect = btnRef.value.getBoundingClientRect()
        palettePos.value = {
            top: rect.bottom + 6,
            left: rect.left + rect.width / 2,
            anchorTop: rect.top,
        }
    }
    open.value = !open.value
}

function close() { open.value = false }
</script>

<template>
    <div class="insert-point" :class="{ 'insert-point--open': open, 'insert-point--show': visible || open }">
        <div class="insert-line" />
        <button ref="btnRef" class="insert-btn" @click="toggle">+ Add block</button>
        <div class="insert-line" />
    </div>

    <Teleport to="body">
        <NodePalette
            v-if="open"
            :after-node-id="afterNodeId"
            :handle="handle"
            :palette-pos="palettePos"
            @select="close"
            @close="close"
        />
    </Teleport>
</template>

<style scoped>
.insert-point--show,
.insert-point--open { opacity: 1; }
</style>
