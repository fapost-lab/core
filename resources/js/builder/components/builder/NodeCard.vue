<script setup lang="ts">
import {computed} from 'vue'
import {nodeColors} from '@builder/utils/nodeColors'
import SendMessageBody from './NodeCardBody/SendMessageBody.vue'
import InputBody from './NodeCardBody/InputBody.vue'
import ConditionBody from './NodeCardBody/ConditionBody.vue'
import DelayBody from './NodeCardBody/DelayBody.vue'

const props = defineProps({
    node:       { type: Object,  required: true },
    isSelected: { type: Boolean, default: false },
    seqNum:     { type: Number,  default: null },
})

const emit = defineEmits(['select', 'navigateBranch'])

const colors = computed(() => nodeColors(props.node.type))

const bodyComponent = computed(() => {
    const map: Record<string, object> = {
        send_message:  SendMessageBody,
        input:         InputBody,
        condition:     ConditionBody,
        delay:         DelayBody,
    }
    return map[props.node.type] ?? null
})
</script>

<template>
    <div
        class="node-card"
        :class="{ selected: isSelected }"
        :id="`node-${node.id}`"
        @click="emit('select', node.id)"
    >
        <!-- Header -->
        <div class="node-card-head">
            <div
                class="node-type-icon"
                :style="{ background: colors.bg, color: colors.color }"
            >
                {{ colors.icon }}
            </div>
            <span class="node-type-label">{{ node.label ?? node.type }}</span>
            <span v-if="seqNum != null" class="node-num">#{{ seqNum }}</span>
        </div>

        <!-- Body -->
        <div v-if="bodyComponent" class="node-card-body">
            <component
                :is="bodyComponent"
                :config="node.config ?? {}"
                :node="node"
                @navigate-branch="(nid: string, bk: string) => emit('navigateBranch', nid, bk)"
            />
        </div>
    </div>
</template>

<style scoped>
.node-card {
    width: 100%;
    max-width: 480px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    cursor: pointer;
    transition: all .15s;
    overflow: hidden;
}
.node-card:hover  { border-color: var(--border-2); box-shadow: var(--shadow-md); }
.node-card.selected {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(90,110,88,.1);
}

.node-card-head {
    padding: 9px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid var(--border);
}
.node-type-icon {
    width: 24px;
    height: 24px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
}
.node-type-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--text-3);
    flex: 1;
}
.node-num {
    font-size: 11px;
    font-family: 'Victor Mono', monospace;
    color: var(--text-3);
    font-weight: 500;
}

.node-card-body { padding: 8px 12px 10px; }
</style>
