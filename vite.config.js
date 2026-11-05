import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        // Camada de compatibilidade da migração: os ecrãs vindos do Next.js
        // (resources/js/legacy) importam next/link e next/navigation.
        alias: {
            'next/link': '/resources/js/compat/next-link.tsx',
            'next/navigation': '/resources/js/compat/next-navigation.ts',
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
