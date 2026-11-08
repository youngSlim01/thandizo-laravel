import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';
import { brandSubtitle } from '@/lib/brand';
import type { SharedProps } from '@/types';

export const initials = (name: string | null | undefined) =>
    (name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '··';

function BrandMark() {
    return <span className="meal-brand-mark"><ThandizoMark /></span>;
}

// Nome e papel de quem está ligado, no canto superior direito; abre o perfil.
export function UserMenu({ className = '' }: { className?: string }) {
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user;
    return (
        <Link href="/account" className={`user-menu ${className}`.trim()} title="Abrir o meu perfil">
            <span className="user-menu-avatar" aria-hidden="true">{initials(user?.full_name)}</span>
            <span className="user-menu-text">
                <strong>{user?.full_name ?? 'Utilizador'}</strong>
                <small>{user?.role_label}{user?.province ? ` · ${user.province}` : ''}</small>
            </span>
        </Link>
    );
}

// Menu lateral do back office, igual ao AdminHeader do sistema Next.js
// (app/components/admin-header.tsx). As secções vêm do servidor
// (NavigationService), já filtradas pelo papel da pessoa.
export function AdminLayout({ children }: { children: ReactNode }) {
    return (
        <main className="admin-shell">
            <AdminHeader />
            {children}
        </main>
    );
}

// Menu lateral, cabeçalho no telemóvel e nome do utilizador no topo.
// topbar=false: o ecrã põe o UserMenu no seu próprio cabeçalho (painel MEAL).
export function AdminHeader({ topbar = true }: { topbar?: boolean }) {
    const { auth, navigation, brand } = usePage<SharedProps>().props;
    const url = usePage().url;
    const pathname = url.split('?')[0];
    const brandHref = navigation[0]?.href ?? '/';
    const [open, setOpen] = useState(false);

    useEffect(() => { setOpen(false); }, [pathname]);
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    return (
        <>
            <aside id="admin-menu" className={open ? 'meal-sidebar admin-sidebar open' : 'meal-sidebar admin-sidebar'}>
                <Link href={brandHref} className="meal-brand">
                    <BrandMark />
                    <div><strong>Thandizo</strong><span>{brandSubtitle(brand).toUpperCase()}</span></div>
                </Link>
                <nav className="meal-nav" aria-label="Navegação principal">
                    {navigation.map((s) => {
                        const active = pathname === s.href || pathname.startsWith(`${s.href}/`);
                        return (
                            <Link key={s.href} href={s.href} className={active ? 'meal-nav-item active' : 'meal-nav-item'}>
                                <span className="admin-nav-icon" aria-hidden="true">{s.icon}</span><span>{s.label}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="meal-sidebar-bottom">
                    <Link href="/logout" method="post" as="button" className="meal-nav-item"><span className="admin-nav-icon">↪</span><span>Sair</span></Link>
                    <div className="meal-footer"><strong>{brand.programme ?? 'Thandizo'}</strong><span>v2.0.0</span></div>
                </div>
            </aside>
            <header className="meal-mobile-header admin-mobile-header">
                <Link href={brandHref} className="meal-mobile-brand"><BrandMark /><strong>Thandizo</strong></Link>
                <Link href="/account" className="mobile-user" title={auth.user?.full_name ?? 'Perfil'} aria-label="Abrir o meu perfil">{initials(auth.user?.full_name)}</Link>
                <button type="button" className="mobile-menu-button" aria-expanded={open} aria-controls="admin-menu" onClick={() => setOpen((o) => !o)}>
                    <span aria-hidden="true">{open ? '✕' : '☰'}</span> Menu
                </button>
            </header>
            {open && <div className="mobile-menu-backdrop" onClick={() => setOpen(false)} />}
            {topbar && <div className="admin-topbar"><UserMenu /></div>}
        </>
    );
}
