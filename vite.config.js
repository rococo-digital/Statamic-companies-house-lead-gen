import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/cp.js',
                'resources/css/cp.css',
            ],
            refresh: true,
        }),
    ],
    build: {
        outDir: 'build',
        manifest: 'manifest.json',
        rollupOptions: {
            input: {
                cp: 'resources/js/cp.js',
                css: 'resources/css/cp.css',
            },
            output: {
                manualChunks: undefined,
            },
        },
    },
    publicDir: false,
    base: '/vendor/ch-lead-gen/build/',
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources'),
        },
    },
}); 