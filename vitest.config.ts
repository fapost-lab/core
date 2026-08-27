import {defineConfig} from 'vitest/config'

/**
 * Unit-test runner for the builder's pure TypeScript utilities. Scoped to
 * `*.test.ts` under `resources/js` — component / DOM tests would need the
 * Vue plugin and jsdom, which we deliberately don't pull in here.
 */
export default defineConfig({
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
})
