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
                        <p>A sua área (POS ou Finanças) ainda está a ser migrada para a nova versão do Thandizo.</p>
                    </div>
                </div>

                <div className="quick-list home-secondary-links">
                    <Link href="/account" className="quick-link">O meu perfil →</Link>
                    <Link href="/logout" method="post" as="button" className="quick-link">Sair</Link>
                </div>
            </section>
        </main>
    );
}
