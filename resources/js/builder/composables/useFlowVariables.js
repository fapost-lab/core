import { computed } from 'vue'
import { useBuilderStore } from '@builder/store/builderStore'

const CONTACT_VARS = [
    { key: 'contact.id',       label: 'ID' },
    { key: 'contact.language', label: 'Language' },
    { key: 'contact.name',     label: 'Name' },
    { key: 'contact.username', label: 'Username' },
    { key: 'contact.phone',    label: 'Phone' },
    { key: 'contact.channel',  label: 'Channel' },
]

const SYSTEM_VARS = [
    { key: 'system.language',    label: 'Language' },
    { key: 'system.started_at',  label: 'Started at' },
    { key: 'system.retry_count', label: 'Retry count' },
]

const RAG_VARS = [
    { key: 'rag.answer',     label: 'Answer' },
    { key: 'rag.found',      label: 'Found' },
    { key: 'rag.confidence', label: 'Confidence' },
    { key: 'rag.intent',     label: 'Intent' },
]

export function useFlowVariables() {
    const builderStore = useBuilderStore()

    const flowVars = computed(() => {
        const seen = new Map()
        const nodes = builderStore.definition?.nodes ?? []

        for (const node of nodes) {
            const config = node.config ?? {}
            const nodeLabel = node.label ?? node.type

            if ((node.type === 'input' || node.type === 'send_message') && config.save_to) {
                const key = `flow.${config.save_to}`
                if (!seen.has(key)) seen.set(key, { key, label: config.save_to, source: nodeLabel })
            }

            if (node.type === 'set_attribute' && config.key) {
                const key = `flow.${config.key}`
                if (!seen.has(key)) seen.set(key, { key, label: config.key, source: nodeLabel })
            }
        }

        return [...seen.values()]
    })

    const groups = computed(() => {
        const result = []
        if (flowVars.value.length > 0) {
            result.push({ ns: 'flow', label: 'Flow', vars: flowVars.value })
        }
        result.push({ ns: 'contact', label: 'Contact', vars: CONTACT_VARS })
        result.push({ ns: 'system',  label: 'System',  vars: SYSTEM_VARS })
        result.push({ ns: 'rag',     label: 'RAG',     vars: RAG_VARS })
        return result
    })

    return { groups, flowVars }
}
