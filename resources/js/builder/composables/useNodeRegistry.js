import { storeToRefs } from 'pinia';
import { useRegistryStore } from '@builder/store/registryStore';

/**
 * Loads node types once and exposes registry state for components.
 */
export function useNodeRegistry() {
    const registry = useRegistryStore();
    const { nodeTypes, loaded } = storeToRefs(registry);

    return {
        nodeTypes,
        loaded,
        load: () => registry.load(),
        getByType: (type, version) => registry.getByType(type, version),
    };
}
