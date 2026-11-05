import { Head, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { useAction } from '@/lib/action';
import { formatDateTime, formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';
import { downloadSettlementPdf, type Settlement, type SettlementSale } from '@/lib/settlement-pdf';
import type { SharedProps } from '@/types';

type Props = { settlements: Settlement[]; agrodealers: { id: string; name: string }[]; canApprove: boolean; openId: string | null };

const SETTLEMENT_STATUS: Record<string, string> = {
    RECONCILED: 'Por enviar', PENDING_MANAGER: 'À espera do gestor MEAL', MANAGER_RETURNED: 'Devolvida pelo gestor',
    SUBMITTED_TO_FINANCE: 'No Finance', VALIDATED: 'Validada pelo Finance', RETURNED: 'Devolvida pelo Finance',
};
const SALE_STATUS: Record<string, string> = { CONFIRMED: 'Confirmada', REVERSED: 'Anulada', PENDING: 'Pendente', REJECTED: 'Rejeitada' };
const statusLabel = (s: string) => SETTLEMENT_STATUS[s] ?? s;
const money = (n: number) => formatMT(Number(n || 0));

// Só se liquidam dias fechados, na hora de Moçambique: por omissão, do
// primeiro dia do mês de ontem até ontem.
function closedPeriod() {
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Maputo' });
    const yesterday = new Date(Date.parse(`${today}T00:00:00Z`) - 86400000).toISOString().slice(0, 10);
    return { yesterday, monthStart: `${yesterday.slice(0, 8)}01` };
}

function SettlementDetail({ settlement, canApprove, onClose }: { settlement: Settlement; canApprove: boolean; onClose: () => void }) {
    const [sales, setSales] = useState<SettlementSale[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const action = useAction();
    const s = settlement;

    useEffect(() => {
        setSales([]); setLoadError(null);
        requestJson<SettlementSale[]>('GET', `/settlements/${s.id}/transactions`).then(setSales).catch((err) => setLoadError(errorText(err)));
    }, [s.id]);

    const error = loadError ?? action.error;

    return (
        <section className="settlement-detail admin-card">
            <div className="settlement-detail-head">
                <div><span className="section-label">Documento de liquidação</span><h2>{s.settlement_code}</h2><p>{s.agrodealer} · {s.period_start} a {s.period_end} · <strong>{statusLabel(s.status)}</strong></p></div>
                <button className="quick-link" onClick={onClose}>Fechar</button>
            </div>
            {error && <div className="error-box">{error}</div>}
            <div className="settlement-kpis">
                <div><span>Transações</span><strong>{s.transaction_count}</strong></div>
                <div><span>Total após desconto</span><strong>{money(s.gross_amount)}</strong></div>
                <div><span>Voucher</span><strong>{money(s.voucher_amount)}</strong></div>
                <div><span>Copagamento</span><strong>{money(s.copayment_amount)}</strong></div>
                <div className="payable"><span>A pagar</span><strong>{money(s.payable_amount)}</strong></div>
            </div>
            <ul className="settlement-trail">
                <li>Criada por <strong>{s.created_by ?? '—'}</strong> em {formatDateTime(s.created_at)}</li>
                {s.submitted_at && <li>Enviada por <strong>{s.submitted_by ?? '—'}</strong> em {formatDateTime(s.submitted_at)}</li>}
                {s.manager_reviewed_at && <li>{s.status === 'MANAGER_RETURNED' ? 'Devolvida' : 'Aprovada'} pelo gestor <strong>{s.manager_reviewed_by ?? '—'}</strong> em {formatDateTime(s.manager_reviewed_at)}</li>}
                {s.manager_notes && <li>Motivo do gestor: {s.manager_notes}</li>}
                {s.status === 'RETURNED' && s.notes && <li>Motivo do Finance: {s.notes}</li>}
            </ul>
            <div className="settlement-actions">
                <button className="secondary-button" onClick={() => void downloadSettlementPdf(s, sales, 'meal', statusLabel(s.status))}>Gerar PDF</button>
                {s.status === 'RECONCILED' && (
                    <button className="primary-button" disabled={action.busy} onClick={() => action.run('post', `/admin/settlements/${s.id}/submission`)}>
                        {action.busy ? 'A enviar...' : canApprove ? 'Enviar para Finance' : 'Enviar ao gestor MEAL'}
                    </button>
                )}
            </div>
            {s.status === 'PENDING_MANAGER' && canApprove && (
                <div className="settlement-review">
                    <button className="primary-button" disabled={action.busy} onClick={() => action.run('post', `/admin/settlements/${s.id}/approval`)}>
                        {action.busy ? 'A processar...' : 'Aprovar e enviar para Finance'}
                    </button>
                    <label><span className="form-label">Motivo (para devolver ao oficial)</span>
                        <textarea className="input" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex.: período errado, vendas em falta" /></label>
                    <button className="secondary-button danger" disabled={action.busy || !reason.trim()}
                        onClick={() => action.run('post', `/admin/settlements/${s.id}/return`, { reason: reason.trim() }, () => setReason(''))}>Devolver ao oficial</button>
                </div>
            )}
            <div className="admin-section-head"><div><span>Transações incluídas</span><small>{sales.length} transação(ões) confirmadas</small></div></div>
            <div className="admin-table-card settlement-items">
                {sales.map((r) => (
                    <div className="admin-transaction-row" key={r.id}>
                        <div><strong>{r.transaction_code}</strong><small>{r.voucher_code} · {formatDateTime(r.confirmed_at)}</small></div>
                        <div className="transaction-money"><strong>{money(r.total_amount)}</strong><small>Voucher {money(r.voucher_amount)} · Copagamento {money(r.copayment_amount)}</small></div>
                        <span className="badge">{SALE_STATUS[r.status] ?? r.status}</span>
                    </div>
                ))}
            </div>
        </section>
    );
}

export default function Settlements({ settlements, agrodealers, canApprove, openId }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const { yesterday, monthStart } = closedPeriod();
    const [dealerId, setDealerId] = useState('');
    const [start, setStart] = useState(monthStart);
    const [end, setEnd] = useState(yesterday);
    const [selectedId, setSelectedId] = useState<string | null>(openId);
    const pendingCount = settlements.filter((x) => x.status === 'PENDING_MANAGER').length;
    const [tab, setTab] = useState<'all' | 'pending'>(canApprove && pendingCount > 0 ? 'pending' : 'all');
    const action = useAction();

    useEffect(() => { if (openId) setSelectedId(openId); }, [openId]);

    const filtered = useMemo(() => settlements.filter((x) => (!dealerId || x.agrodealer_id === dealerId) && (tab === 'all' || x.status === 'PENDING_MANAGER')),
        [settlements, dealerId, tab]);
    const selected = settlements.find((x) => x.id === selectedId) ?? null;

    return (
        <AdminLayout>
            <Head title="Reconciliações" />
            <div className="admin-page-heading"><div><div className="eyebrow">MEAL · RECONCILIAÇÃO</div><h1>Reconciliações</h1>
                <p>{canApprove ? 'Fechar períodos de vendas, aprovar as reconciliações dos oficiais e encaminhá-las para Finance.' : 'Fechar períodos de vendas dos agrodealers da sua província e enviá-los ao gestor MEAL para aprovação.'}</p></div></div>
            {action.error && <div className="error-box">{action.error}</div>}
            {flash.success && <div className="success-box">{flash.success}</div>}
            <section className="settlement-builder admin-card">
                <div><span className="section-label">Nova liquidação</span>
                    <p className="muted-text">Seleccione o agrodealer e o período. Só dias já fechados (até ontem, hora de Moçambique). Entram as vendas confirmadas ainda não liquidadas; paga-se ao agrodealer a parte do voucher.</p></div>
                <div className="settlement-form">
                    <label><span className="form-label">Agrodealer</span>
                        <select className="input" value={dealerId} onChange={(e) => setDealerId(e.target.value)}>
                            <option value="">Seleccionar agrodealer</option>
                            {agrodealers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                        </select></label>
                    <label><span className="form-label">Data inicial</span><input className="input" type="date" value={start} max={yesterday} onChange={(e) => setStart(e.target.value)} /></label>
                    <label><span className="form-label">Data final</span><input className="input" type="date" value={end} max={yesterday} onChange={(e) => setEnd(e.target.value)} /></label>
                    <button className="primary-button" disabled={action.busy || !dealerId}
                        onClick={() => action.run('post', '/admin/settlements', { agrodealer_id: dealerId, period_start: start, period_end: end })}>
                        {action.busy ? 'A processar...' : 'Fechar e criar liquidação'}
                    </button>
                </div>
            </section>
            <div className="admin-section-head"><div><span>Histórico de liquidações</span><small>{filtered.length} documento(s)</small></div>
                {canApprove && (
                    <div className="tx-period-presets settlement-tabs" role="group" aria-label="Mostrar">
                        <button type="button" className={tab === 'pending' ? 'active' : ''} onClick={() => setTab('pending')}>Por aprovar ({pendingCount})</button>
                        <button type="button" className={tab === 'all' ? 'active' : ''} onClick={() => setTab('all')}>Todas</button>
                    </div>
                )}
            </div>
            <div className="settlement-list">
                {filtered.map((s) => (
                    <button className="settlement-row" key={s.id} onClick={() => setSelectedId(s.id)}>
                        <div><strong>{s.settlement_code}</strong><small>{s.agrodealer}{s.province ? ` (${s.province})` : ''} · {s.period_start} a {s.period_end} · por {s.created_by ?? '—'}</small></div>
                        <div><strong>{money(s.payable_amount)}</strong><span className={`settlement-status ${s.status.toLowerCase()}`}>{statusLabel(s.status)}</span></div>
                    </button>
                ))}
                {filtered.length === 0 && <div className="recent-empty">{tab === 'pending' ? 'Nenhuma reconciliação à espera de aprovação.' : 'Ainda não existem liquidações.'}</div>}
            </div>
            {selected && <SettlementDetail key={selected.id} settlement={selected} canApprove={canApprove} onClose={() => setSelectedId(null)} />}
        </AdminLayout>
    );
}
