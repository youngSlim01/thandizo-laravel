import { Head, Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

function greeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Bom dia';
    if (hour < 18) return 'Boa tarde';
    return 'Boa noite';
}

// Página inicial provisória da fundação: confirma quem entrou e com que papel.
// Os painéis por papel (POS, MEAL, Finance) chegam com os seus módulos.
export default function Home() {
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user!;

    return (
        <main className="pos-shell">
            <Head title="Início" />
            <section className="pos-content home-content">
                <div className="home-welcome">
                    <div>
                        <div className="eyebrow">{user.role_label}</div>
                        <h1>{greeting()}, {user.full_name.split(' ')[0]}</h1>
                        <p>THANDIZO em Laravel: fundação pronta. Os módulos chegam um a um.</p>
                    </div>
                </div>

                <div className="quick-list home-secondary-links">
                    {user.can.view_agrodealers && <Link href="/admin/agrodealers" className="quick-link">Agrodealers →</Link>}
                    {user.can.view_products && <Link href="/admin/products" className="quick-link">Produtos e preços →</Link>}
                    {user.can.view_districts && <Link href="/admin/districts" className="quick-link">Distritos do programa →</Link>}
                    <Link href="/logout" method="post" as="button" className="quick-link">Sair</Link>
                </div>
            </section>
        </main>
    );
}
