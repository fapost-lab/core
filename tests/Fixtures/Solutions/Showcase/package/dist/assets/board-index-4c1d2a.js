import { defineComponent, h, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'

export default defineComponent({
    name: 'ShowcaseBoard',
    props: { title: { type: String, default: 'Board' } },
    setup(props) {
        const clicks = ref(0)

        return () => h('section', { class: 'showcase-panel', 'data-showcase': 'board' }, [
            h(Head, { title: props.title }),
            h('h1', props.title),
            h(Button, { onClick: () => clicks.value++ }, () => `Clicked ${clicks.value}`),
        ])
    },
})
