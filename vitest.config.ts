import {defineConfig} from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'
import {fileURLToPath} from 'node:url'

const root = path.dirname(fileURLToPath(import.meta.url))

/**
 * Unit tests for the TypeScript under `resources/js`, in Node by default. A test that mounts components opts into a
 * DOM with `// @vitest-environment happy-dom` and mounts through `@vue/test-utils`; the Vue plugin and the aliases of
 * `vite.config.ts` are here for those.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@builder': path.resolve(root, 'resources/js/builder'),
            '@console': path.resolve(root, 'resources/js/console'),
            '@shared': path.resolve(root, 'resources/js/shared'),
            '@tma': path.resolve(root, 'resources/js/tma'),
            '@fapost/ui': path.resolve(root, 'resources/js/ui'),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
})
