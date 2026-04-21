import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchNodeTypes } from '@builder/api/builderApi';

export const useRegistryStore = defineStore('registry', () => {
    const nodeTypes = ref([]);
    const loaded = ref(false);

    /** @type {Promise<void>|null} */
    let pending = null;

    async function load() {
        if (loaded.value) {
            return;
        }

        if (pending) {
            return pending;
        }

        pending = fetchNodeTypes()
            .then((data) => {
                nodeTypes.value = data;
                loaded.value = true;
            })
            .finally(() => {
                pending = null;
            });

        return pending;
    }

    /**
     * @param {string} type
     * @param {number} version
     */
    function getByType(type, version) {
        return nodeTypes.value.find((n) => n.type === type && n.version === version);
    }

    return { nodeTypes, loaded, load, getByType };
});
