import { useBuilderStore } from '@builder/store/builderStore';
import { validateFlow } from '@builder/api/builderApi';
import type { ValidationResult } from '@builder/dto/types';

export function useValidation() {
    const store = useBuilderStore();

    async function validate(options: { open?: boolean } = {}): Promise<ValidationResult> {
        const { open = true } = options;
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
        const result = await validateFlow(store.flowId!, store.definition, store.trigger);
        store.setValidationResult(result, open);

        return result;
    }

    return { validate };
}
