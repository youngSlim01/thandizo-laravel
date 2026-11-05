import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType, ReactNode } from 'react';
import { AuthProvider } from '@/legacy/app/auth-context';
import PosLayout from '@/legacy/app/pos/layout';

type PageModule = { default: ComponentType & { layout?: (page: ReactNode) => ReactNode } };

// Páginas Inertia em resources/js/pages/<Nome>.tsx, pedidas pelo controller com
// Inertia::render('<Nome>'). Os ecrãs vindos do Next.js vivem em
// resources/js/legacy/app/<caminho>/page.tsx e chamam-se pelo caminho do
// ficheiro: "legacy/app/<caminho>/page".
const pages = import.meta.glob<PageModule>('./pages/**/*.tsx');
const legacyPages = import.meta.glob<PageModule>('./legacy/app/**/page.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · THANDIZO` : 'THANDIZO'),
    resolve: async (name) => {
        if (name.startsWith('legacy/')) {
            const loader = legacyPages[`./${name}.tsx`];
            if (!loader) throw new Error(`Página Inertia não encontrada: ${name}`);
            const page = (await loader()).default;
            // Os passos do POS partilham a venda em curso: layout persistente.
            if (name.startsWith('legacy/app/pos/')) page.layout = (child: ReactNode) => <PosLayout>{child}</PosLayout>;
            return page;
        }
        const loader = pages[`./pages/${name}.tsx`];
        if (!loader) throw new Error(`Página Inertia não encontrada: ${name}`);
        return (await loader()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<AuthProvider><App {...props} /></AuthProvider>);
    },
    progress: { color: '#176b4d' },
});
