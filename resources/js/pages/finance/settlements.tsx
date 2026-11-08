import { Head, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { FinanceLayout } from '@/components/finance-layout';
import { useAction } from '@/lib/action';
import { formatDateTime, formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';
import { useBrand } from '@/lib/brand';
import { downloadSettlementPdf, type Settlement, type SettlementSale } from '@/lib/settlement-pdf';
import type { SharedProps } from '@/types';

const STATUS_LABEL: Record<string, string> = {
    RECONCILED: 'Não submetida', PENDING_MANAGER: 'No gestor MEAL', MANAGER_RETURNED: 'Devolvida pelo gestor',
    SUBMITTED_TO_FINANCE: 'Aguardando validação', VALIDATED: 'Validada', RETURNED: 'Devolvida',
};
const label = (status: string) => STATUS_LABEL[status] ?? status;
const money = (n: number) => formatMT(Number(n || 0));

function Detail({ settlement, onClose }: { settlement: Settlement; onClose: () => void }) {
    const brand = useBrand();
    const [sales, setSales] = useState<SettlementSale[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const action = useAction();
    const s = settlement;

    useEffect(() => {
        requestJson<SettlementSale[]>('GET', `/settlements/${s.id}/transactions`).then(setSales).catch((err) => setLoadError(errorText(err)));
    }, [s.id]);

    const error = loadError ?? action.error;

    return (
        <section className="settlement-detail admin-card finance-detail">
            <div className="settlement-detail-head">
                <div>
                    <button className="quick-link" onClick={onClose}>← Voltar para a lista</button>
                    <div className="detail-title-row"><h2>{s.settlement_code}</h2><span className={`settlement-status ${s.status.toLowerCase()}`}>{label(s.status)}</span></div>
                    <p>Revisar o mapa de vendas reconciliado pelo MEAL</p>
                </div>
                <button className="secondary-button" onClick={() => void downloadSettlementPdf(s, sales, 'finance', label(s.status), brand)}>Baixar mapa PDF</button>
            </div>
            {error && <div className="error-box">{error}</div>}

            <div className="finance-detail-grid">
                <div className="admin-card finance-summary-card">
                    <span className="section-label">Informações gerais</span>
                    <div className="finance-info-list">
                        <div><span>Comerciante</span><strong>{s.agrodealer}</strong></div>
                        <div><span>Período</span><strong>{s.period_start} · {s.period_end}</strong></div>
                        <div><span>Número de transações</span><strong>{s.transaction_count}</strong></div>
                        <div><span>Valor bruto das vendas</span><strong>{money(s.gross_amount)}</strong></div>
                        <div><span>Valor em vouchers</span><strong>{money(s.voucher_amount)}</strong></div>
                        <div><span>Copagamento</span><strong>{money(s.copayment_amount)}</strong></div>
                        <div className="finance-payable-row"><span>Valor da reconciliação</span><strong>{money(s.payable_amount)}</strong></div>
                    </div>
                </div>

                <div className="finance-decision-card finance-action-card">
                    <span className="section-label">Acções Finance</span>
                    {s.status === 'SUBMITTED_TO_FINANCE' ? (
                        <>
                            <p className="muted-text">Confirme que recebeu e validou a reconciliação enviada pelo MEAL.</p>
                            <button className="primary-button" disabled={action.busy} onClick={() => action.run('post', `/finance/settlements/${s.id}/validation`)}>
                                {action.busy ? 'A validar...' : '✓ Recebido / Validado'}
                            </button>
                            <div className="finance-return-divider"><span>ou</span></div>
                            <label><span className="form-label">Observação para o MEAL</span>
                                <textarea className="input" rows={4} placeholder="Indique o que precisa de ser corrigido..." value={reason} onChange={(e) => setReason(e.target.value)} /></label>
                            <button className="secondary-button danger-action" disabled={action.busy || !reason.trim()}
                                onClick={() => action.run('post', `/finance/settlements/${s.id}/return`, { reason: reason.trim() })}>↩ Devolver ao MEAL</button>
                        </>
                    ) : s.status === 'VALIDATED' ? (
                        <div className="success-box"><strong>✓ Reconciliação validada</strong><br /><span>Finance confirmou o recebimento e a validação do documento.</span></div>
                    ) : s.status === 'RETURNED' ? (
                        <div className="error-box"><strong>Reconciliação devolvida ao MEAL.</strong><br /><span>{s.notes}</span></div>
                    ) : (
                        <div className="info-box">Esta reconciliação ainda não foi submetida pelo MEAL.</div>
                    )}
                </div>
            </div>

            <div className="admin-section-head"><div><span>Transações incluídas ({sales.length})</span><small>Apenas transações confirmadas incluídas nesta reconciliação</small></div></div>
            <div className="admin-table-card settlement-items">
                {sales.map((r) => (
                    <div className="admin-transaction-row finance-tx-row" key={r.id}>
                        <div><strong>{r.voucher_code}</strong><small>{formatDateTime(r.confirmed_at)} · {r.transaction_code}</small></div>
                        <div className="transaction-money"><strong>{money(r.total_amount)}</strong><small>Voucher {money(r.voucher_amount)} · Copagamento {money(r.copayment_amount)}</small></div>
                        <span className="badge">{r.status}</span>
                    </div>
                ))}
            </div>
        </section>
    );
}

export default function FinanceSettlements({ settlements, openId }: { settlements: Settlement[]; openId: string | null }) {
    const { flash } = usePage<SharedProps>().props;
    const [selectedId, setSelectedId] = useState<string | null>(openId);
    const [filter, setFilter] = useState('ALL');
    const [period, setPeriod] = useState('ALL');
    const [q, setQ] = useState('');

    const periods = useMemo(() => Array.from(new Set(settlements.map((r) => r.period_start.slice(0, 7)))).sort((a, b) => b.localeCompare(a)), [settlements]);
    const visible = useMemo(() => settlements.filter((r) => {
        const term = q.trim().toLowerCase();
        return (filter === 'ALL' || r.status === filter)
            && (period === 'ALL' || r.period_start.slice(0, 7) === period)
            && (!term || r.settlement_code.toLowerCase().includes(term) || r.agrodealer.toLowerCase().includes(term));
    }), [settlements, filter, period, q]);

    const awaiting = settlements.filter((r) => r.status === 'SUBMITTED_TO_FINANCE').length;
    const validated = settlements.filter((r) => r.status === 'VALIDATED').length;
    const returned = settlements.filter((r) => r.status === 'RETURNED').length;
    const selected = settlements.find((r) => r.id === selectedId) ?? null;

    return (
        <FinanceLayout>
            <Head title="Liquidações" />
            <div className="finance-settlements-main">
                <div className="admin-page-heading"><div>
                    <div className="eyebrow">FINANCE · RECONCILIAÇÃO</div>
                    <h1>Liquidações</h1>
                    <p>Reconciliações recebidas do MEAL para conferência e validação.</p>
                </div></div>
                {flash.success && <div className="success-box">{flash.success}</div>}

                <div className="meal-metrics finance-metrics">
                    <div className="meal-metric accent"><span>Aguardando validação</span><strong>{awaiting}</strong><small>Recebidas do MEAL</small></div>
                    <div className="meal-metric"><span>Validadas</span><strong>{validated}</strong><small>Conferência concluída</small></div>
                    <div className="meal-metric"><span>Devolvidas</span><strong>{returned}</strong><small>Aguardam correcção pelo MEAL</small></div>
                    <div className="meal-metric"><span>Total</span><strong>{settlements.length}</strong><small>Reconciliações registadas</small></div>
                </div>

                <div className="admin-filters finance-toolbar">
                    <select className="input" value={period} onChange={(e) => setPeriod(e.target.value)}>
                        <option value="ALL">Todos os períodos</option>
                        {periods.map((p) => <option key={p} value={p}>{new Date(`${p}-01T12:00:00`).toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' })}</option>)}
                    </select>
                    <input className="input" placeholder="Pesquisar por referência ou comerciante..." value={q} onChange={(e) => setQ(e.target.value)} />
                </div>

                <div className="finance-tabs">
                    {([['ALL', 'Todas', settlements.length], ['SUBMITTED_TO_FINANCE', 'Aguardando validação', awaiting], ['VALIDATED', 'Validadas', validated], ['RETURNED', 'Devolvidas', returned]] as const).map(([value, text, count]) => (
                        <button key={value} className={filter === value ? 'finance-tab active' : 'finance-tab'} onClick={() => setFilter(value)}>{text}<span>{count}</span></button>
                    ))}
                </div>

                <div className="settlement-list finance-list">
                    {visible.map((r) => (
                        <button key={r.id} className="settlement-row finance-row" onClick={() => setSelectedId(r.id)}>
                            <div><strong>{r.settlement_code}</strong><small>{r.agrodealer} · {r.period_start} a {r.period_end}</small></div>
                            <div className="finance-row-middle"><span>{r.transaction_count} transações</span><strong>{money(r.payable_amount)}</strong></div>
                            <div className="finance-row-end">
                                <span className={`settlement-status ${r.status.toLowerCase()}`}>{label(r.status)}</span>
                                <small>{r.submitted_at ? formatDateTime(r.submitted_at) : 'Ainda não enviada'}</small>
                            </div>
                        </button>
                    ))}
                    {visible.length === 0 && <div className="recent-empty">Nenhuma reconciliação encontrada.</div>}
                </div>

                {selected && <Detail key={selected.id} settlement={selected} onClose={() => setSelectedId(null)} />}
            </div>
        </FinanceLayout>
    );
}
