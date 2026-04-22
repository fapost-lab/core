<script setup>
import { onMounted, ref } from 'vue'
import { useEventListener } from '@vueuse/core'
import { useBuilderStore }  from '@builder/store/builderStore'
import { useRegistryStore } from '@builder/store/registryStore'
import { useAutoSave }      from '@builder/composables/useAutoSave'
import { useValidation }    from '@builder/composables/useValidation'
import { usePublish }       from '@builder/composables/usePublish'
import AppTopBar            from '@builder/components/AppTopBar.vue'
import FlowStructure        from '@builder/components/editor/FlowStructure.vue'
import FlowSequence         from '@builder/components/editor/FlowSequence.vue'
import ConfigPanel          from '@builder/components/editor/ConfigPanel.vue'
import ContentTab           from '@builder/components/content/ContentTab.vue'
import ValidationPanel      from '@builder/components/editor/ValidationPanel.vue'

const props = defineProps({
    flow:    { type: Object, required: true },
    backUrl: { type: String, default: null },
})

const builderStore  = useBuilderStore()
const registryStore = useRegistryStore()
const { save }      = useAutoSave()
const { validate }  = useValidation()
const { publish }   = usePublish()
const validating = ref(false)
const publishing = ref(false)
const publishedMsg = ref(null)

builderStore.init(props.flow)

onMounted(async () => {
    await registryStore.load()
})

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
</script>

<template>
    <div class="h-screen flex flex-col">
        <AppTopBar
            :flow-name="builderStore.flowName"
            :draft-version="builderStore.draftVersion"
            :published-version="builderStore.publishedVersion"
            :save-status="builderStore.saveStatus"
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
            @rollback="() => {}"
            @preview="() => {}"
        />

        <div
            v-if="builderStore.activeTab === 'builder'"
            class="flex-1 flex overflow-hidden"
        >
            <FlowStructure class="w-56 border-r shrink-0 overflow-y-auto" />
            <FlowSequence class="flex-1 overflow-y-auto px-8 py-6" />
            <ConfigPanel class="w-72 border-l shrink-0 overflow-y-auto" />
        </div>

        <ContentTab v-else />
        <ValidationPanel />
    </div>
</template>
