
import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useAuth } from '../auth-context';
import { OperatorGate } from '../components/operator-gate';
import { PosHeader } from '../components/pos-header';
import { callRpc } from '../../lib/supabase';
import { errorMessage } from '../../lib/errors';
import { formatMT, formatTime } from '../../lib/format';
import { listMyRecentSales, RecentSale } from '../../lib/sales';

type VoidResult = { success: boolean; duplicate: boolean; transaction_code: string; copayment_to_refund?: number; voucher_amount_restored?: number };

const statusLabel: Record<string, string> = { CONFIRMED: 'Confirmada', REVERSED: 'Anulada', PENDING: 'Pendente', REJECTED: 'Rejeitada' };

function VoidForm({ sale, onDone }: { sale: RecentSale; onDone: (result: VoidResult) => void }) {
  const auth = useAuth();
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent) {
    event.preventDefault(); setError(null); setSubmitting(true);
    try {
      const result = await callRpc<VoidResult>('void_voucher_transaction', { p_transaction_id: sale.transaction_id, p_operator_id: auth.operator?.id, p_pos_id: auth.pos?.id, p_reason: reason });
      onDone(result);
    } catch (err) { setError(errorMessage(err)); }
    finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="void-form">
      <label className="form-label" htmlFor={`reason-${sale.transaction_id}`}>Motivo da anulação</label>
      <input id={`reason-${sale.transaction_id}`} className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: quantidade errada" required />
      {error && <div className="error-box">{error}</div>}
      <button className="danger-button" type="submit" disabled={submitting || !reason.trim()}>{submitting ? 'A ANULAR…' : 'CONFIRMAR ANULAÇÃO'}</button>
    </form>
  );
}

function SalesList() {
  const [sales, setSales] = useState<RecentSale[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [voiding, setVoiding] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState<'ALL' | 'CONFIRMED' | 'REVERSED'>('ALL');

  const load = useCallback(() => {
    setError(null);
    listMyRecentSales().then(setSales).catch((err) => setError(errorMessage(err)));
  }, []);

  useEffect(() => { load(); }, [load]);

  function voided(result: VoidResult) {
    setVoiding(null);
    const refund = Number(result.copayment_to_refund ?? 0);
    setNotice(`Venda ${result.transaction_code} anulada. O saldo do voucher foi reposto.${refund > 0 ? ` Devolva ${formatMT(refund)} em dinheiro ao agregado.` : ''}`);
    load();
  }

  const filtered = useMemo(() => {
    if (!sales) return [];
    const q = query.trim().toLowerCase();
    return sales.filter((sale) => {
      const matchesStatus = status === 'ALL' || sale.status === status;
      const matchesQuery = !q || sale.transaction_code.toLowerCase().includes(q) || sale.voucher_code.toLowerCase().includes(q);
      return matchesStatus && matchesQuery;
    });
  }, [sales, query, status]);

  const confirmed = sales?.filter((s) => s.status === 'CONFIRMED') ?? [];
  const total = confirmed.reduce((sum, s) => sum + Number(s.total_amount), 0);

  return (
    <main className="pos-shell">
      <PosHeader title="Vendas" back="/" />
      <section className="pos-content sales-content">
        <div className="eyebrow">Últimas 24 horas · suas transações</div>
        <div className="sales-title-row"><div><h1>Vendas</h1><p>Consulte recibos e faça anulações quando ainda estiverem dentro do prazo.</p></div><Link href="/pos/voucher" className="sales-new-button">+ Nova venda</Link></div>

        <div className="sales-metrics">
          <div><span>Transações</span><strong>{sales ? sales.length : '—'}</strong></div>
          <div><span>Confirmadas</span><strong>{sales ? confirmed.length : '—'}</strong></div>
          <div><span>Valor confirmado</span><strong>{sales ? formatMT(total) : '—'}</strong></div>
        </div>

        <div className="sales-toolbar">
          <input className="input sales-search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Pesquisar transação ou voucher..." />
          <div className="sales-filters">
            {([['ALL','Todas'],['CONFIRMED','Confirmadas'],['REVERSED','Anuladas']] as const).map(([value,label]) => (
              <button key={value} className={status === value ? 'sales-filter active' : 'sales-filter'} onClick={() => setStatus(value)}>{label}</button>
            ))}
          </div>
        </div>

        {notice && <div className="success-box">{notice}</div>}
        {error && <div className="error-box">{error}</div>}
        {sales === null && !error && <div className="loading-card"><span className="loading-dot" /> A carregar vendas...</div>}
        {sales && filtered.length === 0 && <div className="sales-empty"><strong>Nenhuma venda encontrada</strong><span>Tente outro código ou altere o filtro.</span></div>}

        <div className="sales-list">
          {filtered.map((sale) => (
            <article className={sale.status === 'REVERSED' ? 'sale-record reversed' : 'sale-record'} key={sale.transaction_id}>
              <div className="sale-record-head">
                <div className="sale-code"><span className="sale-icon">↗</span><div><strong>{sale.transaction_code}</strong><small>{formatTime(sale.confirmed_at)} · {sale.voucher_code}</small></div></div>
                <span className={sale.status === 'REVERSED' ? 'sale-status reversed' : 'sale-status'}>{statusLabel[sale.status] ?? sale.status}</span>
              </div>
              <div className="sale-record-values">
                <div><span>Total</span><strong>{formatMT(sale.total_amount)}</strong></div>
                <div><span>Voucher</span><strong>{formatMT(sale.voucher_amount)}</strong></div>
                <div><span>Co-pagamento</span><strong>{formatMT(sale.copayment_amount)}</strong></div>
              </div>
              <div className="sale-record-actions">
                <Link href={`/receipt/${sale.transaction_id}`} className="quick-link">Ver recibo</Link>
                {sale.can_void && voiding !== sale.transaction_id && <button className="quick-link danger" onClick={() => { setNotice(null); setVoiding(sale.transaction_id); }}>Anular · até {formatTime(sale.void_deadline)}</button>}
              </div>
              {voiding === sale.transaction_id && <VoidForm sale={sale} onDone={voided} />}
            </article>
          ))}
        </div>

        <Link href="/pos/voucher" className="primary-button link-button sales-mobile-new">NOVA VENDA</Link>
      </section>
    </main>
  );
}

export default function SalesPage() {
  return <OperatorGate><SalesList /></OperatorGate>;
}