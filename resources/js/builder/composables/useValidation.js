import { useBuilderStore } from '@builder/store/builderStore';
import { validateFlow } from '@builder/api/builderApi';

export function useValidation() {
    const store = useBuilderStore();

    /**
     * @param {{ open?: boolean }} [options]
     */
    async function validate(options = {}) {
        const { open = true } = options;
        const result = await validateFlow(store.flowId, store.definition, store.trigger);
        store.setValidationResult(result, open);

        return result;
    }

    return { validate };
}
