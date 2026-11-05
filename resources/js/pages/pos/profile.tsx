import { Head, Link, usePage } from '@inertiajs/react';
import { PosHeader } from '@/components/pos/pos-header';
import { changePos, signOut } from '@/components/pos/pos-layout';
import type { SharedProps } from '@/types';

export default function Profile() {
    const user = usePage<SharedProps>().props.auth.user;

    return (
        <main className="pos-shell">
            <Head title="Perfil" />
            <PosHeader title="Perfil" back="/" />
            <section className="pos-content profile-content">
                <div className="profile-hero">
                    <div className="profile-avatar">{user?.full_name.charAt(0).toUpperCase() ?? 'O'}</div>
                    <div>
                        <span className="eyebrow">Operador</span>
                        <h1>{user?.full_name ?? 'Operador'}</h1>
                        <p>{user?.role_label}</p>
                    </div>
                </div>

                <div className="profile-card">
                    <div className="profile-card-title">Local de trabalho</div>
                    <div className="profile-detail"><span>Agrodealer</span><strong>{user?.agrodealer_name ?? '—'}</strong></div>
                    <div className="profile-detail"><span>POS seleccionado</span><strong>{user?.pos?.code ?? 'Nenhum'}</strong></div>
                    {user?.pos?.label && <div className="profile-detail"><span>Dispositivo</span><strong>{user.pos.label}</strong></div>}
                </div>

                <div className="profile-card">
                    <div className="profile-card-title">Estado da aplicação</div>
                    <div className="profile-status"><span className="online-dot" /><strong>Sistema online</strong><small>Sessão activa e pronta para vendas.</small></div>
                </div>

                {(user?.pos_list.length ?? 0) > 1 && (
                    <div className="profile-card">
                        <div className="profile-card-title">Terminal</div>
                        <p>Está a trabalhar no POS <strong>{user?.pos?.code ?? '—'}</strong>.</p>
                        <button className="secondary-button" onClick={changePos}>MUDAR DE POS</button>
                    </div>
                )}

                <Link href="/sales" className="quick-link profile-link">Ver minhas vendas →</Link>
                <Link href="/account" className="quick-link profile-link">Alterar palavra-passe →</Link>
                <button className="quick-link profile-signout" onClick={signOut}>Sair da aplicação</button>
            </section>
        </main>
    );
}
