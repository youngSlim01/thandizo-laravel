import { Head, Link, usePage } from '@inertiajs/react';
import { PosHeader } from '@/components/pos/pos-header';
import { changePos, signOut } from '@/components/pos/pos-layout';
import { formatDate, formatMT } from '@/lib/format';
import { isToday, type RecentSale } from '@/lib/sales';
import type { SharedProps } from '@/types';

function greeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Bom dia';
    if (hour < 18) return 'Boa tarde';
    return 'Boa noite';
}

type Fair = { district: string | null; status: 'none' | 'planned' | 'ongoing' | 'finished'; starts_on?: string; ends_on?: string };

// Só se vende nos dias da feira do distrito do comerciante.
function fairText(fair: Fair): string {
    const where = fair.district ? ` de ${fair.district}` : '';
    switch (fair.status) {
        case 'ongoing': return `Feira${where} a decorrer até ${formatDate(fair.ends_on)}.`;
        case 'planned': return `Feira${where} marcada de ${formatDate(fair.starts_on)} a ${formatDate(fair.ends_on)}. As vendas abrem nesse dia.`;
        case 'finished': return `A feira${where} terminou a ${formatDate(fair.ends_on)}. As vendas estão fechadas.`;
        default: return `Ainda não há feira marcada${where}. As vendas estão fechadas.`;
    }
}

export default function PosHome({ sales, fair }: { sales: RecentSale[]; fair: Fair }) {
    const user = usePage<SharedProps>().props.auth.user;
    const today = sales.filter((s) => s.status === 'CONFIRMED' && isToday(s.confirmed_at));
    const voucherTotal = today.reduce((sum, s) => sum + Number(s.voucher_amount), 0);

    return (
        <main className="pos-shell">
            <Head title="Início" />
            <PosHeader title="Início" />
            <section className="pos-content home-content">
                <div className="home-welcome">
                    <div>
                        <div className="eyebrow">{user?.agrodealer_name} · POS {user?.pos?.code}</div>
                        <h1>{greeting()}, {user?.full_name.split(' ')[0]}</h1>
                        <p>Pronto para atender o próximo beneficiário.</p>
                    </div>
                    <div className="online-badge"><span className="online-dot" /> POS online</div>
                </div>

                <div className={fair.status === 'ongoing' ? 'pos-fair pos-fair-open' : 'pos-fair'}>{fairText(fair)}</div>

                {fair.status === 'ongoing' ? (
                    <Link href="/pos/voucher" className="new-sale-button link-button home-sale-cta">
                        <span className="new-sale-icon">+</span>
                        <span><strong>Nova venda</strong><small>Validar voucher e registar uma compra</small></span>
                        <span className="new-sale-arrow">›</span>
                    </Link>
                ) : (
                    <div className="new-sale-button home-sale-cta pos-sale-closed" aria-disabled="true">
                        <span className="new-sale-icon">+</span>
                        <span><strong>Nova venda</strong><small>Só nos dias da feira</small></span>
                    </div>
                )}

                <div className="dashboard-grid">
                    <div className="metric-card">
                        <div className="metric-label">Vendas hoje</div>
                        <div className="metric-value">{today.length}</div>
                        <div className="metric-caption">transações confirmadas</div>
                    </div>
                    <div className="metric-card metric-card-accent">
                        <div className="metric-label">Valor em vouchers</div>
                        <div className="metric-value">{formatMT(voucherTotal)}</div>
                        <div className="metric-caption">valor processado hoje</div>
                    </div>
                </div>

                <Link href="/catalogue" className="home-catalogue-link">
                    <span><strong>Catálogo de produtos</strong><small>{user?.role === 'AGRODEALER_MANAGER' ? 'Adicionar ou retirar produtos; preços do distrito' : 'Produtos e preços deste comerciante'}</small></span>
                    <span>›</span>
                </Link>

                <div className="home-section-head">
                    <div><span>Últimas vendas</span><small>Atividade recente deste POS</small></div>
                    <Link href="/sales">Ver todas →</Link>
                </div>

                <div className="recent-sales-card">
                    {today.length === 0 && <div className="recent-empty">Ainda não existem vendas confirmadas hoje.</div>}
                    {today.slice(0, 5).map((sale) => (
                        <Link href={`/receipt/${sale.transaction_id}`} className="recent-sale-row" key={sale.transaction_id}>
                            <span className="recent-sale-icon">↗</span>
                            <span className="recent-sale-main"><strong>{sale.transaction_code}</strong><small>Voucher {sale.voucher_code}</small></span>
                            <span className="recent-sale-value">
                                <strong>{formatMT(Number(sale.voucher_amount) + Number(sale.copayment_amount))}</strong>
                                <small>{new Date(sale.confirmed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small>
                            </span>
                        </Link>
                    ))}
                </div>

                <div className="quick-list home-secondary-links">
                    {(user?.pos_list.length ?? 0) > 1 && <button className="quick-link" onClick={changePos}>Mudar de POS →</button>}
                    <button className="quick-link" onClick={signOut}>Sair</button>
                </div>
            </section>
        </main>
    );
}
