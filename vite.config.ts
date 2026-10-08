import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

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
                'resources/js/tma/app.ts',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@builder': path.resolve(__dirname, 'resources/js/builder'),
            '@shared': path.resolve(__dirname, 'resources/js/shared'),
            '@tma': path.resolve(__dirname, 'resources/js/tma'),
            '@fapost/ui': path.resolve(__dirname, 'resources/js/ui'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
