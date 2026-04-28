import { storeToRefs } from 'pinia';
import { useRegistryStore } from '@builder/store/registryStore';
import type { NodeTypePayload } from '@builder/dto/types';

/** Loads node types once and exposes registry state for components. */
export function useNodeRegistry() {
    const registry = useRegistryStore();
    const { nodeTypes, loaded } = storeToRefs(registry);

    return {
        nodeTypes,
        loaded,
        load: (): Promise<void> => registry.load(),
        getByType: (type: string, version: number): NodeTypePayload | undefined =>
            registry.getByType(type, version),
    };
}
