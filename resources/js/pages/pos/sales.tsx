import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';
import { PosHeader } from '@/components/pos/pos-header';
import { useAction } from '@/lib/action';
import { formatMT, formatTime } from '@/lib/format';
import type { RecentSale } from '@/lib/sales';
import type { SharedProps } from '@/types';

const STATUS_LABEL: Record<string, string> = { CONFIRMED: 'Confirmada', REVERSED: 'Anulada', PENDING: 'Pendente', REJECTED: 'Rejeitada' };

function VoidForm({ sale, onDone }: { sale: RecentSale; onDone: () => void }) {
    const [reason, setReason] = useState('');
    const action = useAction();

    function submit(event: FormEvent) {
        event.preventDefault();
        action.run('post', `/sales/${sale.transaction_id}/void`, { reason }, onDone);
    }

    return (
        <form onSubmit={submit} className="void-form">
            <label className="form-label" htmlFor={`reason-${sale.transaction_id}`}>Motivo da anulação</label>
            <input id={`reason-${sale.transaction_id}`} className="input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: quantidade errada" required />
            {action.error && <div className="error-box">{action.error}</div>}
            <button className="danger-button" type="submit" disabled={action.busy || !reason.trim()}>{action.busy ? 'A ANULAR…' : 'CONFIRMAR ANULAÇÃO'}</button>
        </form>
    );
}

export default function Sales({ sales }: { sales: RecentSale[] }) {
    const { flash } = usePage<SharedProps>().props;
    const [voiding, setVoiding] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<'ALL' | 'CONFIRMED' | 'REVERSED'>('ALL');

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return sales.filter((sale) => (status === 'ALL' || sale.status === status)
            && (!q || sale.transaction_code.toLowerCase().includes(q) || sale.voucher_code.toLowerCase().includes(q)));
    }, [sales, query, status]);

    const confirmed = sales.filter((s) => s.status === 'CONFIRMED');
    const total = confirmed.reduce((sum, s) => sum + Number(s.total_amount), 0);

    return (
        <main className="pos-shell">
            <Head title="Vendas" />
            <PosHeader title="Vendas" back="/" />
            <section className="pos-content sales-content">
                <div className="eyebrow">Últimas 24 horas · suas transações</div>
                <div className="sales-title-row">
                    <div><h1>Vendas</h1><p>Consulte recibos e faça anulações quando ainda estiverem dentro do prazo.</p></div>
                    <Link href="/pos/voucher" className="sales-new-button">+ Nova venda</Link>
                </div>

                <div className="sales-metrics">
                    <div><span>Transações</span><strong>{sales.length}</strong></div>
                    <div><span>Confirmadas</span><strong>{confirmed.length}</strong></div>
                    <div><span>Valor confirmado</span><strong>{formatMT(total)}</strong></div>
                </div>

                <div className="sales-toolbar">
                    <input className="input sales-search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Pesquisar transação ou voucher..." />
                    <div className="sales-filters">
                        {([['ALL', 'Todas'], ['CONFIRMED', 'Confirmadas'], ['REVERSED', 'Anuladas']] as const).map(([value, label]) => (
                            <button key={value} className={status === value ? 'sales-filter active' : 'sales-filter'} onClick={() => setStatus(value)}>{label}</button>
                        ))}
                    </div>
                </div>

                {flash.success && <div className="success-box">{flash.success}</div>}
                {filtered.length === 0 && <div className="sales-empty"><strong>Nenhuma venda encontrada</strong><span>Tente outro código ou altere o filtro.</span></div>}

                <div className="sales-list">
                    {filtered.map((sale) => (
                        <article className={sale.status === 'REVERSED' ? 'sale-record reversed' : 'sale-record'} key={sale.transaction_id}>
                            <div className="sale-record-head">
                                <div className="sale-code"><span className="sale-icon">↗</span><div><strong>{sale.transaction_code}</strong><small>{formatTime(sale.confirmed_at)} · {sale.voucher_code}</small></div></div>
                                <span className={sale.status === 'REVERSED' ? 'sale-status reversed' : 'sale-status'}>{STATUS_LABEL[sale.status] ?? sale.status}</span>
                            </div>
                            <div className="sale-record-values">
                                <div><span>Total</span><strong>{formatMT(sale.total_amount)}</strong></div>
                                <div><span>Voucher</span><strong>{formatMT(sale.voucher_amount)}</strong></div>
                                <div><span>Co-pagamento</span><strong>{formatMT(sale.copayment_amount)}</strong></div>
                            </div>
                            <div className="sale-record-actions">
                                <Link href={`/receipt/${sale.transaction_id}`} className="quick-link">Ver recibo</Link>
                                {sale.can_void && voiding !== sale.transaction_id && (
                                    <button className="quick-link danger" onClick={() => setVoiding(sale.transaction_id)}>Anular · até {formatTime(sale.void_deadline)}</button>
                                )}
                            </div>
                            {voiding === sale.transaction_id && <VoidForm sale={sale} onDone={() => setVoiding(null)} />}
                        </article>
                    ))}
                </div>

                <Link href="/pos/voucher" className="primary-button link-button sales-mobile-new">NOVA VENDA</Link>
            </section>
        </main>
    );
}
