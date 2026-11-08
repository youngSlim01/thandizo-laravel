import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';
import type { SharedProps } from '@/types';
import { signOut } from './pos-layout';

const NAV = [
    { href: '/', label: 'Início', icon: '⌂' },
    { href: '/pos/voucher', label: 'Nova venda', icon: '+' },
    { href: '/sales', label: 'Vendas', icon: '▤' },
    { href: '/profile', label: 'Perfil', icon: '◉' },
];

export function PosHeader({ title, back, right }: { title: string; back?: string; right?: ReactNode }) {
    const page = usePage<SharedProps>();
    const user = page.props.auth.user;
    const brand = page.props.brand;
    const pathname = page.url.split('?')[0];
    const active = (href: string) => (href === '/' ? pathname === '/' : pathname.startsWith(href));

    return (
        <>
            <aside className="app-sidebar no-print">
                <Link href="/" className="app-logo" aria-label="THANDIZO">
                    <span className="app-logo-mark"><ThandizoMark /></span>
                    <span><strong>Thandizo</strong><small>{(brand.client ?? 'E-voucher').toUpperCase()} · POS</small></span>
                </Link>
                <nav className="app-nav" aria-label="Navegação principal">
                    {NAV.map((item) => (
                        <Link key={item.href} href={item.href} className={active(item.href) ? 'app-nav-item active' : 'app-nav-item'}>
                            <span className="app-nav-icon">{item.icon}</span><span>{item.label}</span>
                        </Link>
                    ))}
                </nav>
                <div className="sidebar-footer">
                    <div className="device-status"><span className="online-dot" /> Online</div>
                    <div className="sidebar-user">
                        <strong>{user?.full_name.split(' ')[0] ?? 'Operador'}</strong>
                        <span>{user?.pos?.code ?? 'POS'}</span>
                    </div>
                    <button className="sidebar-signout" onClick={signOut}>Sair</button>
                </div>
            </aside>

            <header className="pos-header">
                {back ? <Link href={back} className="header-back" aria-label="Voltar">‹</Link> : <span className="header-back" />}
                <div className="header-title">
                    <span className="header-brand">Thandizo</span>
                    <span className="header-page">{title}</span>
                </div>
                <div className="header-status">
                    <span className="online-dot" />
                    <span className="header-pos">{right ?? user?.pos?.code ?? ''}</span>
                </div>
            </header>

            <nav className="mobile-nav no-print" aria-label="Navegação móvel">
                {NAV.map((item) => (
                    <Link key={item.href} href={item.href} className={active(item.href) ? 'mobile-nav-item active' : 'mobile-nav-item'}>
                        <span className="mobile-nav-icon">{item.icon}</span><span>{item.label}</span>
                    </Link>
                ))}
            </nav>
        </>
    );
}

const STEPS = ['Voucher', 'Produtos', 'Carrinho', 'Desconto', 'Pagamento', 'Confirmar'];

// Barra dos 6 passos da venda; current começa em 0.
export function CheckoutSteps({ current }: { current: number }) {
    return (
        <>
            <div className="checkout-steps no-print">
                {STEPS.map((label, index) => (
                    <div className={index === current ? 'checkout-step active' : index < current ? 'checkout-step done' : 'checkout-step'} key={label}>
                        <span>0{index + 1}</span><strong>{label}</strong>
                    </div>
                ))}
            </div>
            <div className="eyebrow">Passo {current + 1} de 6</div>
        </>
    );
}
