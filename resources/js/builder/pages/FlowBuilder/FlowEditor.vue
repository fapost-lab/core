<script setup lang="ts">
import {onMounted, ref} from 'vue'
import {useEventListener} from '@vueuse/core'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'
import {useAutoSave} from '@builder/composables/useAutoSave'
import {useValidation} from '@builder/composables/useValidation'
import {usePublish} from '@builder/composables/usePublish'
import AppTopBar from '@builder/components/AppTopBar.vue'
import FlowStructure from '@builder/components/editor/FlowStructure.vue'
import FlowSequence from '@builder/components/editor/FlowSequence.vue'
import ConfigPanel from '@builder/components/editor/ConfigPanel.vue'
import ContentTab from '@builder/components/content/ContentTab.vue'
import ValidationPanel from '@builder/components/editor/ValidationPanel.vue'
import ConfirmDialog from '@builder/components/editor/ConfirmDialog.vue'
import MoveNodeDialog from '@builder/components/editor/MoveNodeDialog.vue'
import DeleteNodeDialog from '@builder/components/editor/DeleteNodeDialog.vue'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import {useConfirm} from '@builder/composables/useConfirm'
import {useTranslations} from '@builder/composables/useTranslations'

import type {BuilderFlowPayload} from '@builder/dto/types'

const props = defineProps({
    flow:    { type: Object as () => BuilderFlowPayload, required: true },
    backUrl: { type: String as () => string | null, default: null },
})

const builderStore  = useBuilderStore()
const registryStore = useRegistryStore()
const { save }      = useAutoSave()
const { validate }  = useValidation()
const { publish }   = usePublish()
const moveNode   = useMoveNode()
const deleteNode = useDeleteNode()
const { confirm } = useConfirm()
const { t }       = useTranslations()
const validating = ref(false)
const publishing = ref(false)
const publishedMsg = ref<string | null>(null)

builderStore.init(props.flow)

onMounted(async () => {
    await registryStore.load()
})

/**
 * Recover from a save conflict by re-reading the draft the server actually
 * holds. A full reload rather than a store re-init: the flow arrives as an
 * Inertia prop, and reloading also drops the undo stack, selection and branch
 * pointer that all refer to the version being discarded.
 */
async function runReload() {
    const ok = await confirm({
        title:        t('topbar.reload_confirm_title'),
        message:      t('topbar.reload_confirm_message'),
        confirmLabel: t('topbar.reload_confirm_ok'),
        cancelLabel:  t('topbar.reload_confirm_cancel'),
        danger:       true,
    })

    if (ok) {
        window.location.reload()
    }
}

async function runValidate() {
    validating.value = true

    try {
        await validate()
    } finally {
        validating.value = false
    }
}

async function runPublish() {
    publishing.value = true
    publishedMsg.value = null

    try {
        const result = await publish()
        if (result.success) {
            publishedMsg.value = `Published as v${result.version}`
            setTimeout(() => {
                publishedMsg.value = null
            }, 3000)
        }
    } finally {
        publishing.value = false
    }
}

useEventListener(document, 'keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'z' && !event.shiftKey) {
        event.preventDefault()
        builderStore.undo()
        return
    }

    if ((event.metaKey || event.ctrlKey) && (event.key === 'y' || (event.key === 'z' && event.shiftKey))) {
        event.preventDefault()
        builderStore.redo()
    }
})

// Browser-level navigation guard. Auto-save debounces 1.5s before pushing
// to the API, so closing the tab in that window would silently drop edits.
// Returning a string from the handler triggers the standard "leave site?"
// prompt; we only opt in while there is something genuinely unsaved.
useEventListener(window, 'beforeunload', (event: BeforeUnloadEvent) => {
    if (builderStore.isDirty) {
        event.preventDefault()
        // Modern browsers ignore the message, but `returnValue` is still
        // required to actually surface the prompt.
        event.returnValue = ''
    }
})
</script>

<template>
    <div class="editor-root">
        <AppTopBar
            :flow-name="builderStore.flowName"
            :draft-version="builderStore.draftVersion"
            :published-version="builderStore.publishedVersion"
            :save-status="builderStore.saveStatus"
            :is-dirty="builderStore.isDirty"
            :active-tab="builderStore.activeTab"
            :back-url="backUrl"
            :can-undo="builderStore.undoStack.length > 0"
            :can-redo="builderStore.redoStack.length > 0"
            :validating="validating"
            :publishing="publishing"
            :published-msg="publishedMsg"
            @tab-change="builderStore.setActiveTab"
            @save-draft="save"
            @undo="builderStore.undo"
            @redo="builderStore.redo"
            @publish="runPublish"
            @validate="runValidate"
            @reload="runReload"
        />

        <div
            v-if="builderStore.activeTab === 'builder'"
            class="editor-main"
            :class="{ 'editor-main--val-open': builderStore.validationOpen }"
        >
            <FlowStructure />
            <FlowSequence />
            <ConfigPanel />
        </div>

        <ContentTab v-else class="editor-main" />
        <ValidationPanel />
        <ConfirmDialog />
        <MoveNodeDialog
            :chain="moveNode.targetAsChain.value"
            :node-id="moveNode.targetNodeId.value"
            :open="moveNode.targetNodeId.value !== null"
            @close="moveNode.close"
        />
        <DeleteNodeDialog
            :node-id="deleteNode.targetNodeId.value"
            :open="deleteNode.targetNodeId.value !== null"
            @close="deleteNode.close"
        />
    </div>
</template>

<style scoped>
.editor-root {
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.editor-main {
    flex: 1;
    display: flex;
    overflow: hidden;
    min-height: 0;
    /* Anchor for the absolutely-positioned config drawer (ConfigPanel). */
    position: relative;
}
</style>
