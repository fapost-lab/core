import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * The signed-in user's permission names, shared by the server on every Inertia page as `auth.permissions`
 * (`HandleInertiaRequests::share()`).
 * The server stays the only arbiter: the builder uses this only to spare the user a refusal.
 */
export function usePermissions() {
    const page = usePage()

    const granted = computed<string[]>(() => {
        const auth = (page.props as Record<string, unknown>)['auth']
        const list = typeof auth === 'object' && auth !== null ? (auth as Record<string, unknown>)['permissions'] : undefined

        return Array.isArray(list) ? list.filter((p): p is string => typeof p === 'string') : []
    })

    function can(permission: string): boolean {
        return granted.value.includes(permission)
    }

    /**
     * Whether a trigger of this type may be removed in the builder: a message trigger always, an automatic one
     * (anything else) only with the Manage automations permission. The tree and the graph both ask here.
     */
    function canRemoveTrigger(type: string): boolean {
        return type === 'message' || can('manage_automations')
    }

    return { can, canRemoveTrigger }
}
