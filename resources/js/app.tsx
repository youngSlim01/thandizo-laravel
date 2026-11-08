import '@fontsource-variable/montserrat';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType, ReactNode } from 'react';
import { PosLayout } from '@/components/pos/pos-layout';

type PageModule = { default: ComponentType & { layout?: (page: ReactNode) => ReactNode } };

// Páginas Inertia em resources/js/pages/<nome>.tsx, pedidas pelo controller
// com Inertia::render('<nome>').
const pages = import.meta.glob<PageModule>('./pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · Thandizo` : 'Thandizo'),
    resolve: async (name) => {
        const loader = pages[`./pages/${name}.tsx`];
        if (!loader) throw new Error(`Página Inertia não encontrada: ${name}`);
        const page = (await loader()).default;
        // As páginas do POS partilham a venda em curso: layout persistente.
        if (name.startsWith('pos/')) page.layout = (child: ReactNode) => <PosLayout>{child}</PosLayout>;
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#007716' },
});
