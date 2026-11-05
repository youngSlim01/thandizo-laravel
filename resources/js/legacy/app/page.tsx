
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuth } from './auth-context';
import { OperatorGate } from './components/operator-gate';
import { PosHeader } from './components/pos-header';
import { formatMT } from '../lib/format';
import { isToday, listMyRecentSales, RecentSale } from '../lib/sales';

function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'Bom dia';
  if (hour < 18) return 'Boa tarde';
  return 'Boa noite';
}

function Dashboard() {
  const auth = useAuth();
  const [sales, setSales] = useState<RecentSale[] | null>(null);

  useEffect(() => {
    listMyRecentSales().then(setSales).catch(() => setSales(null));
  }, []);

  const today = (sales ?? []).filter((s) => s.status === 'CONFIRMED' && isToday(s.confirmed_at));
  const voucherTotal = today.reduce((sum, s) => sum + Number(s.voucher_amount), 0);

  return (
    <main className="pos-shell">
      <PosHeader title="Início" />
      <section className="pos-content home-content">
        <div className="home-welcome">
          <div>
            <div className="eyebrow">{auth.operator?.agrodealerName} · POS {auth.pos?.code}</div>
            <h1>{greeting()}, {auth.operator?.fullName?.split(' ')[0]}</h1>
            <p>Pronto para atender o próximo beneficiário.</p>
          </div>
          <div className="online-badge"><span className="online-dot" /> POS online</div>
        </div>

        <Link href="/pos/voucher" className="new-sale-button link-button home-sale-cta">
          <span className="new-sale-icon">+</span>
          <span><strong>Nova venda</strong><small>Validar voucher e registar uma compra</small></span>
          <span className="new-sale-arrow">›</span>
        </Link>

        <div className="dashboard-grid">
          <div className="metric-card">
            <div className="metric-label">Vendas hoje</div>
            <div className="metric-value">{sales ? today.length : '—'}</div>
            <div className="metric-caption">transações confirmadas</div>
          </div>
          <div className="metric-card metric-card-accent">
            <div className="metric-label">Valor em vouchers</div>
            <div className="metric-value">{sales ? formatMT(voucherTotal) : '—'}</div>
            <div className="metric-caption">valor processado hoje</div>
          </div>
        </div>

        <Link href="/catalogue" className="home-catalogue-link">
          <span><strong>Catálogo de produtos</strong><small>{auth.operator?.role === 'AGRODEALER_MANAGER' ? 'Adicionar ou retirar produtos; preços do distrito' : 'Produtos e preços deste agrodealer'}</small></span>
          <span>›</span>
        </Link>

        <div className="home-section-head">
          <div><span>Últimas vendas</span><small>Atividade recente deste POS</small></div>
          <Link href="/sales">Ver todas →</Link>
        </div>

        <div className="recent-sales-card">
          {!sales && <div className="recent-empty">A carregar vendas...</div>}
          {sales && today.length === 0 && <div className="recent-empty">Ainda não existem vendas confirmadas hoje.</div>}
          {sales && today.slice(0, 5).map((sale) => (
            <Link href={`/receipt/${sale.transaction_id}`} className="recent-sale-row" key={sale.transaction_id}>
              <span className="recent-sale-icon">↗</span>
              <span className="recent-sale-main"><strong>{sale.transaction_code}</strong><small>Voucher {sale.voucher_code}</small></span>
              <span className="recent-sale-value"><strong>{formatMT(Number(sale.voucher_amount) + Number(sale.copayment_amount))}</strong><small>{new Date(sale.confirmed_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small></span>
            </Link>
          ))}
        </div>

        <div className="quick-list home-secondary-links">
          {auth.posList.length > 1 && (
            <button className="quick-link" onClick={() => auth.selectPos('')}>Mudar de POS →</button>
          )}
          <button className="quick-link" onClick={() => auth.signOut()}>Sair</button>
        </div>
      </section>
    </main>
  );
}

export default function HomePage() {
  return <OperatorGate><Dashboard /></OperatorGate>;
}