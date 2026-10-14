import {defineConfig, type Plugin} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Entries a prebuilt Solution bundle imports through the console's import map (ADR solution-console-ui). They are
 * built with their full export signature; without `preserveEntrySignatures: 'strict'` Vite tree-shakes an entry's
 * exports away and leaves an empty module.
 */
const sharedEntries = [
    'resources/js/console/shared/vue.ts',
    'resources/js/console/shared/inertia.ts',
    'resources/js/console/shared/ui.ts',
];

/**
 * Fails the build when a shared entry comes out without exports, so a lost `preserveEntrySignatures` breaks CI
 * instead of every Solution page in production.
 */
function sharedEntriesKeepTheirExports(): Plugin {
    return {
        name: 'fapost:shared-entries-keep-their-exports',
        apply: 'build',
        generateBundle(_options, bundle) {
            for (const entry of sharedEntries) {
                const chunk = Object.values(bundle).find(
                    (output) => output.type === 'chunk' && output.isEntry && output.facadeModuleId === path.resolve(__dirname, entry),
                );

                if (chunk === undefined || chunk.type !== 'chunk' || chunk.exports.length === 0) {
                    this.error(`The shared entry ${entry} was built without exports; keep build.rolldownOptions.preserveEntrySignatures: 'strict'.`);
                }
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/builder.css',
                'resources/css/ui.css',
                'resources/css/filament/theme.css',
                'resources/js/app.js',
                'resources/js/builder/app.ts',
                'resources/js/console/app.ts',
                'resources/js/tma/app.ts',
                ...sharedEntries,
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        sharedEntriesKeepTheirExports(),
    ],
    resolve: {
        alias: {
            '@builder': path.resolve(__dirname, 'resources/js/builder'),
            '@shared': path.resolve(__dirname, 'resources/js/shared'),
            '@tma': path.resolve(__dirname, 'resources/js/tma'),
            '@fapost/ui': path.resolve(__dirname, 'resources/js/ui'),
        },
    },
    build: {
        rolldownOptions: {
            preserveEntrySignatures: 'strict',
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
