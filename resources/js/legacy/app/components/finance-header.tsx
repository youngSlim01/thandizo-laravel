
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '../auth-context';
import { UserMenu, initials } from './user-menu';

function Mark() {
  return (
    <div className="finance-brand-mark" aria-hidden="true">
      <span />
      <span />
      <span />
    </div>
  );
}

export function FinanceHeader() {
  const auth = useAuth();
  const pathname = usePathname();
  // On a phone the menu is a panel opened by the ☰ button.
  const [open, setOpen] = useState(false);
  useEffect(() => { setOpen(false); }, [pathname]);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);
  const links = [
    { href: '/finance', label: 'Painel', icon: '▣' },
    { href: '/finance/settlements', label: 'Reconciliações', icon: '▤' },
    { href: '/finance/transactions', label: 'Transações', icon: '⇄' },
    { href: '/finance/reports', label: 'Relatórios', icon: '◫' },
  ];

  return (
    <>
      <aside id="finance-menu" className={open ? 'finance-sidebar open' : 'finance-sidebar'}>
        <Link href="/finance" className="finance-brand">
          <Mark />
          <div>
            <strong>THANDIZO</strong>
            <span>E-VOUCHER ASSISTANCE<br />PLATFORM</span>
          </div>
        </Link>

        <nav className="finance-nav">
          {links.map((link) => {
            const active = link.href === '/finance'
              ? pathname === '/finance'
              : pathname.startsWith(link.href);
            return (
              <Link key={link.href} href={link.href} className={active ? 'finance-nav-link active' : 'finance-nav-link'}>
                <span className="finance-nav-icon">{link.icon}</span>
                <span>{link.label}</span>
              </Link>
            );
          })}
        </nav>

        <div className="finance-sidebar-bottom">
          <button className="finance-nav-link finance-logout" onClick={() => auth.signOut()}><span className="finance-nav-icon">↪</span><span>Sair</span></button>
          <div className="finance-sci-footer">
            <strong>◉ Save the Children</strong>
            <span>v1.0.0</span>
          </div>
        </div>
      </aside>

      <header className="finance-header-bar">
        <Link href="/finance" className="finance-mobile-brand"><Mark /><strong>THANDIZO</strong></Link>
        <UserMenu className="finance-user" />
        <Link href="/account" className="mobile-user" title={auth.operator?.fullName ?? 'Perfil'} aria-label="Abrir o meu perfil">{initials(auth.operator?.fullName)}</Link>
        <button type="button" className="mobile-menu-button" aria-expanded={open} aria-controls="finance-menu" onClick={() => setOpen((o) => !o)}>
          <span aria-hidden="true">{open ? '✕' : '☰'}</span> Menu
        </button>
      </header>
      {open && <div className="mobile-menu-backdrop" onClick={() => setOpen(false)} />}
    </>
  );
}
