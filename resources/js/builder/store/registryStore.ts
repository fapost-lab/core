import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchNodeTypes } from '@builder/api/builderApi'
import type { NodeGroup, NodeTypePayload, StateNamespaceEntry } from '@builder/dto/types'

export const useRegistryStore = defineStore('registry', () => {
    const nodeTypes = ref<NodeTypePayload[]>([])
    const groups = ref<NodeGroup[]>([])
    const stateNamespaces = ref<StateNamespaceEntry[]>([])
    const loaded = ref(false)
    let pending: Promise<void> | null = null

    async function load(): Promise<void> {
        if (loaded.value) {
            return
        }

        if (pending) {
            return pending
        }

        pending = fetchNodeTypes()
            .then((response) => {
                nodeTypes.value = response.data
                groups.value = response.groups ?? []
                stateNamespaces.value = response.state_namespaces ?? []
                loaded.value = true
            })
            .finally(() => {
                pending = null
            })

        return pending
    }

    function getByType(type: string, version: number): NodeTypePayload | undefined {
        return nodeTypes.value.find((n) => n.type === type && n.version === version)
    }

    return { nodeTypes, groups, stateNamespaces, loaded, load, getByType }
})
