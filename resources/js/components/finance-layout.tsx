import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';
import { brandSubtitle } from '@/lib/brand';
import type { SharedProps } from '@/types';
import { AdminLayout, initials, UserMenu } from './admin-layout';

function Mark() {
    return <span className="finance-brand-mark"><ThandizoMark /></span>;
}

// Área das Finanças, com o desenho próprio do sistema antigo. O menu vem do
// servidor (NavigationService), como no back office.
export function FinanceLayout({ children }: { children: ReactNode }) {
    const { auth, navigation, brand } = usePage<SharedProps>().props;
    const pathname = usePage().url.split('?')[0];
    const [open, setOpen] = useState(false);
    const home = navigation[0]?.href ?? '/';

    useEffect(() => { setOpen(false); }, [pathname]);
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open]);

    return (
        <div className="finance-app">
            <aside id="finance-menu" className={open ? 'finance-sidebar open' : 'finance-sidebar'}>
                <Link href={home} className="finance-brand">
                    <Mark />
                    <div><strong>Thandizo</strong><span>{brandSubtitle(brand).toUpperCase()}</span></div>
                </Link>
                <nav className="finance-nav">
                    {navigation.map((link) => {
                        const active = link.href === '/finance' ? pathname === '/finance' : pathname === link.href || pathname.startsWith(`${link.href}/`);
                        return (
                            <Link key={link.href} href={link.href} className={active ? 'finance-nav-link active' : 'finance-nav-link'}>
                                <span className="finance-nav-icon">{link.icon}</span><span>{link.label}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="finance-sidebar-bottom">
                    <Link href="/logout" method="post" as="button" className="finance-nav-link finance-logout"><span className="finance-nav-icon">↪</span><span>Sair</span></Link>
                    <div className="finance-sci-footer"><strong>{brand.programme ?? 'THANDIZO'}</strong><span>v2.0.0</span></div>
                </div>
            </aside>

            <header className="finance-header-bar">
                <Link href={home} className="finance-mobile-brand"><Mark /><strong>Thandizo</strong></Link>
                <UserMenu className="finance-user" />
                <Link href="/account" className="mobile-user" title={auth.user?.full_name ?? 'Perfil'} aria-label="Abrir o meu perfil">{initials(auth.user?.full_name)}</Link>
                <button type="button" className="mobile-menu-button" aria-expanded={open} aria-controls="finance-menu" onClick={() => setOpen((o) => !o)}>
                    <span aria-hidden="true">{open ? '✕' : '☰'}</span> Menu
                </button>
            </header>
            {open && <div className="mobile-menu-backdrop" onClick={() => setOpen(false)} />}
            <main className="finance-main">{children}</main>
        </div>
    );
}

// Páginas partilhadas pelo MEAL e pelas Finanças (transações, relatórios,
// produtos): o Oficial de Finanças vê-as na sua área, os outros no back office.
export function BackOfficeLayout({ children }: { children: ReactNode }) {
    const role = usePage<SharedProps>().props.auth.user?.role;
    return role === 'FINANCE_OFFICER' ? <FinanceLayout>{children}</FinanceLayout> : <AdminLayout>{children}</AdminLayout>;
}
