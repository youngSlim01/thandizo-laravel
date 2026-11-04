import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

// Cada página Inertia vive em resources/js/pages/<Nome>.tsx e é pedida pelo
// controller com Inertia::render('<Nome>', [...props]).
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · THANDIZO` : 'THANDIZO'),
    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`];
        if (!page) throw new Error(`Página Inertia não encontrada: ${name}`);
        return (await page()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#176b4d' },
});
