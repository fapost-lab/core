import type { DefineComponent } from 'vue'
import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { createPinia } from 'pinia'
import { connectBroadcaster, type BroadcasterProp } from '@fapost/ui/lib/live-updates'

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob<{ default: DefineComponent }>('../pages/**/*.vue', { eager: true })

        return pages[`../pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .mount(el)

        // Live updates over a websocket only when the server says a broadcaster delivers and someone is signed in; the
        // Echo chunk is not even fetched otherwise, and every screen polls. Re-checked on every visit, so signing out
        // closes the socket; an unchanged prop leaves it alone.
        const loadEcho = () => import('./echo')
        void connectBroadcaster(props.initialPage.props.broadcaster as BroadcasterProp | undefined, loadEcho)
        router.on('navigate', (event) => {
            void connectBroadcaster(event.detail.page.props.broadcaster as BroadcasterProp | undefined, loadEcho)
        })
    },
})
