<script setup lang="ts">
import { useConfirm } from '@builder/composables/useConfirm'
import BaseModal from '@builder/components/editor/config/overrides/BaseModal.vue'

// Global confirm dialog. Mounted once in FlowEditor and driven by the
// module-level `useConfirm()` singleton — callers anywhere in the builder
// can `await confirm({...})` for a styled prompt that matches the rest
// of the UI instead of the browser's native dialog.
const { active, resolveActive } = useConfirm()

function onConfirm() {
    resolveActive(true)
}

function onCancel() {
    resolveActive(false)
}
</script>

<template>
    <BaseModal
        :open="active !== null"
        :title="active?.title ?? ''"
        width="420px"
        @close="onCancel"
    >
        <p class="confirm-message">{{ active?.message }}</p>

        <template #footer>
            <button type="button" class="btn btn-ghost" @click="onCancel">
                {{ active?.cancelLabel }}
            </button>
            <button
                type="button"
                class="btn"
                :class="active?.danger ? 'btn-danger' : 'btn-primary'"
                @click="onConfirm"
            >
                {{ active?.confirmLabel }}
            </button>
        </template>
    </BaseModal>
</template>

<style scoped>
.confirm-message {
    font-size: 13px;
    line-height: 1.55;
    color: var(--text);
    margin: 0;
}
.btn {
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: background .12s, color .12s, border-color .12s;
}
.btn-ghost {
    background: transparent;
    border-color: var(--border);
    color: var(--text-2);
}
.btn-ghost:hover { color: var(--text); background: var(--surface-2); }
.btn-primary {
    background: var(--primary);
    color: #fff;
}
.btn-primary:hover { filter: brightness(.95); }
.btn-danger {
    background: var(--rose);
    color: #fff;
}
.btn-danger:hover { filter: brightness(.95); }
</style>
