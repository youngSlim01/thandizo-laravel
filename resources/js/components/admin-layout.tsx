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

// Sino das notificações, ao lado do nome: o que está à espera de quem está
// ligado (NotificationService). Abre uma lista com ligação para cada caso.
export function NotificationBell() {
    const { notifications } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const total = notifications.reduce((sum, n) => sum + n.value, 0);
    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => { if (!(e.target as HTMLElement).closest('.notify')) setOpen(false); };
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('click', close); document.removeEventListener('keydown', onKey); };
    }, [open]);

    return (
        <div className="notify">
            <button type="button" className="notify-button" aria-expanded={open} aria-label={total > 0 ? `Notificações: ${total}` : 'Notificações'} onClick={() => setOpen((o) => !o)}>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15L6 16Z" /><path d="M10 20a2 2 0 0 0 4 0" /></svg>
                {total > 0 && <span className="notify-count">{total > 99 ? '99+' : total}</span>}
            </button>
            {open && (
                <div className="notify-panel" role="dialog" aria-label="Notificações">
                    <strong>Notificações</strong>
                    {notifications.length === 0 && <p>Nada à sua espera.</p>}
                    {notifications.map((n) => (
                        <Link key={n.key} href={n.href} className={`notify-item notify-${n.level}`} onClick={() => setOpen(false)}>
                            <b>{n.value}</b><span>{n.label}</span><i aria-hidden="true">›</i>
                        </Link>
                    ))}
                </div>
            )}
        </div>
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
                    <div className="meal-footer"><strong>{brand.programme ?? 'Thandizo'}</strong><span>v1.0</span></div>
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
            {topbar && <div className="admin-topbar"><NotificationBell /><UserMenu /></div>}
        </>
    );
}
