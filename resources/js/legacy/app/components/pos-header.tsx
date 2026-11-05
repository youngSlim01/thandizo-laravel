
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuth } from '../auth-context';

export function PosHeader({ title, back, right }: { title: string; back?: string; right?: React.ReactNode }) {
  const auth = useAuth();
  const pathname = usePathname();

  const navItems = [
    { href: '/', label: 'Início', icon: '⌂' },
    { href: '/pos/voucher', label: 'Nova venda', icon: '+' },
    { href: '/sales', label: 'Vendas', icon: '▤' },
    { href: '/profile', label: 'Perfil', icon: '◉' },
  ];

  return (
    <>
      <aside className="app-sidebar no-print">
        <Link href="/" className="app-logo" aria-label="LINK Market">
          <span className="app-logo-mark">L</span>
          <span>
            <strong>LINK MARKET</strong>
            <small>CVA · POS</small>
          </span>
        </Link>

        <nav className="app-nav" aria-label="Navegação principal">
          {navItems.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={(item.href === '/' ? pathname === '/' : pathname.startsWith(item.href)) ? 'app-nav-item active' : 'app-nav-item'}
            >
              <span className="app-nav-icon">{item.icon}</span>
              <span>{item.label}</span>
            </Link>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div className="device-status"><span className="online-dot" /> Online</div>
          <div className="sidebar-user">
            <strong>{auth.operator?.fullName?.split(' ')[0] ?? 'Operador'}</strong>
            <span>{auth.pos?.code ?? 'POS'}</span>
          </div>
          <button className="sidebar-signout" onClick={() => auth.signOut()}>Sair</button>
        </div>
      </aside>

      <header className="pos-header">
        {back ? (
          <Link href={back} className="header-back" aria-label="Voltar">‹</Link>
        ) : (
          <span className="header-back" />
        )}
        <div className="header-title">
          <span className="header-brand">LINK MARKET</span>
          <span className="header-page">{title}</span>
        </div>
        <div className="header-status">
          <span className="online-dot" />
          <span className="header-pos">{right ?? auth.pos?.code ?? ''}</span>
        </div>
      </header>

      <nav className="mobile-nav no-print" aria-label="Navegação móvel">
        {navItems.map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className={(item.href === '/' ? pathname === '/' : pathname.startsWith(item.href)) ? 'mobile-nav-item active' : 'mobile-nav-item'}
          >
            <span className="mobile-nav-icon">{item.icon}</span>
            <span>{item.label}</span>
          </Link>
        ))}
      </nav>
    </>
  );
}