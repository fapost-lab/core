import { createRouter, createWebHistory } from 'vue-router'
import FormView from './pages/FormView.vue'
import NotFound from './pages/NotFound.vue'

const routes = [
    { path: '/tma/form/:formId', component: FormView },
    { path: '/:pathMatch(.*)*', component: NotFound },
]

export default createRouter({
    history: createWebHistory(),
    routes,
})
