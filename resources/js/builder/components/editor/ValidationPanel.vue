<script setup lang="ts">
import {useBuilderStore} from '@builder/store/builderStore';
import {useRegistryStore} from '@builder/store/registryStore';
import {useSelectionStore} from '@builder/store/selectionStore';
import {useValidation} from '@builder/composables/useValidation';

const store = useBuilderStore();
const registryStore = useRegistryStore();
const selectionStore = useSelectionStore();
const { validate } = useValidation();

function jumpToNode(error: { path?: string; message?: string }) {
    if (error?.path?.startsWith('trigger.')) {
        selectionStore.selectTrigger()
        return
    }

    const nodeId = error?.path?.split('.')?.[1];
    if (!nodeId) return;
    selectionStore.select(nodeId);
    const target = document.getElementById(`node-card-${nodeId}`);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function humanPath(path: string | undefined): string {
    if (!path) return '';

    if (path.startsWith('trigger.')) {
        const rest = path.slice('trigger.'.length);
        return rest ? `Trigger → ${rest}` : 'Trigger';
    }

    const match = path.match(/^nodes\.([^.]+)(?:\.(.+))?$/);
    if (!match) return path;

    const nodeId = match[1];
    const rest   = match[2];

    const node = store.definition.nodes.find((n) => n.id === nodeId);
    if (!node) return path;

    const typeInfo  = registryStore.getByType(node.type, node.version ?? 1);
    const nodeLabel = typeInfo?.label ?? node.type;

    return rest ? `${nodeLabel} → ${rest}` : nodeLabel;
}
</script>

<template>
    <Transition name="slide-up">
        <div v-if="store.validationOpen" class="val-panel">
            <div class="val-header">
                <div class="val-header-left">
                    <span class="val-title">Validation</span>
                    <span
                        v-if="store.validationResult"
                        class="val-badge"
                        :class="store.validationResult.valid ? 'val-badge--ok' : 'val-badge--err'"
                    >
                        {{ store.validationResult.valid ? 'Valid' : `${store.validationResult.errors?.length ?? 0} errors` }}
                    </span>
                </div>
                <div class="val-header-right">
                    <button class="btn btn-ghost" style="font-size:11.5px;padding:3px 8px" @click="() => validate()">Revalidate</button>
                    <button class="val-close" @click="store.closeValidation()">✕</button>
                </div>
            </div>

            <div class="val-body">
                <div v-if="store.validationResult?.valid" class="val-ok">No errors found. Ready to publish.</div>

                <template v-else-if="store.validationResult?.errors?.length">
                    <button
                        v-for="error in store.validationResult.errors"
                        :key="`${error.path}:${error.message}`"
                        class="val-error-row"
                        @click="jumpToNode(error)"
                    >
                        <span class="val-dot">●</span>
                        <div>
                            <code class="val-path">{{ humanPath(error.path) }}</code>
                            <div class="val-msg">{{ error.message }}</div>
                        </div>
                    </button>
                </template>

                <div v-else class="val-empty">Run validation to check your flow.</div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.val-panel {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    z-index: 40;
    background: var(--surface);
    border-top: 1px solid var(--border);
    box-shadow: var(--shadow-md);
}
.val-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 20px;
    border-bottom: 1px solid var(--border);
}
.val-header-left { display: flex; align-items: center; gap: 8px; }
.val-header-right { display: flex; align-items: center; gap: 6px; }
.val-title { font-size: 13px; font-weight: 600; color: var(--text); }
.val-badge {
    font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 500;
}
.val-badge--ok  { background: var(--sage-bg);  color: var(--sage); }
.val-badge--err { background: var(--rose-bg);  color: var(--rose); }
.val-close {
    background: none; border: none; cursor: pointer;
    font-size: 13px; color: var(--text-3); padding: 2px 6px;
    transition: color .12s;
}
.val-close:hover { color: var(--text); }
.val-body { max-height: 192px; overflow-y: auto; padding: 10px 20px; }
.val-ok   { font-size: 13px; color: var(--sage); }
.val-empty { font-size: 13px; color: var(--text-3); }
.val-error-row {
    display: flex; align-items: flex-start; gap: 10px;
    width: 100%; text-align: left;
    padding: 6px 8px; border-radius: 6px;
    background: none; border: none; cursor: pointer;
    transition: background .1s;
    margin-bottom: 2px;
}
.val-error-row:hover { background: var(--rose-bg); }
.val-dot { color: var(--rose); font-size: 10px; margin-top: 3px; flex-shrink: 0; }
.val-path { font-family: 'Victor Mono', monospace; font-size: 11px; color: var(--text-3); }
.val-msg  { font-size: 12.5px; color: var(--text-2); margin-top: 1px; }

.btn { padding: 6px 12px; border-radius: var(--radius); font-family: 'DM Sans', sans-serif;
  font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: all .15s; }
.btn-ghost { background: transparent; color: var(--text-2); border-color: var(--border); }
.btn-ghost:hover { background: var(--surface-2); color: var(--text); }

.slide-up-enter-active, .slide-up-leave-active { transition: transform .2s ease; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(100%); }
</style>
