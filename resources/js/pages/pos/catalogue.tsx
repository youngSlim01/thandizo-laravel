import { Head, usePage } from '@inertiajs/react';
import { CatalogueEditor } from '@/components/catalogue-editor';
import { PosHeader } from '@/components/pos/pos-header';
import type { SharedProps } from '@/types';

// Catálogo do próprio agrodealer. O gestor junta ou retira produtos; o preço
// vem sempre do distrito. Os operadores só o vêem.
export default function Catalogue({ canManage }: { canManage: boolean }) {
    const user = usePage<SharedProps>().props.auth.user;

    return (
        <main className="pos-shell">
            <Head title="Catálogo" />
            <PosHeader title="Catálogo" back="/" />
            <section className="pos-content">
                <div className="eyebrow">{user?.agrodealer_name}</div>
                <h1>Catálogo de produtos</h1>
                <p>Os produtos que este comerciante vende, aos preços do distrito.</p>
                {user?.agrodealer_id && <CatalogueEditor agrodealerId={user.agrodealer_id} canManage={canManage} />}
            </section>
        </main>
    );
}
