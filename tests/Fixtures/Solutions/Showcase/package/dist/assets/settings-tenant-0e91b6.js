import { defineComponent, h } from 'vue'
import { Badge } from '@fapost/ui/components/badge'

export default defineComponent({
    name: 'ShowcaseTenantSettings',
    props: { recordId: { type: String, default: null }, assistantId: { type: String, default: null } },
    setup(props) {
        return () => h('div', { class: 'showcase-panel', 'data-showcase': 'settings-tenant' }, [
            h(Badge, { variant: 'secondary' }, () => 'Tenant settings'),
            props.recordId ? h('span', { class: 'showcase-mark' }, props.recordId) : null,
        ])
    },
})
