import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { printAgrodealerReport, type AgrodealerReport } from '@/lib/agrodealer-report';
import { formatDateTime, formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';
import type { SharedProps } from '@/types';

// Relatório de transações por agrodealer (PDF). Cada impressão fica
// registada no servidor; o gestor MEAL vê todas, o oficial as da província.

type Dealer = { id: string; code: string; name: string };
type Print = {
    id: string; printed_at: string; printed_by: string | null; printed_by_role: string | null;
    agrodealer_id: string; agrodealer: string; agrodealer_code: string; province: string | null;
    period_start: string; period_end: string; transactions: number; total_amount: number;
};
type Props = { agrodealers: Dealer[]; prints: Print[]; seesAllPrints: boolean };

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Maputo' });
const day = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;

export default function Reports({ agrodealers, prints, seesAllPrints }: Props) {
    const user = usePage<SharedProps>().props.auth.user;
    const [dealerId, setDealerId] = useState('');
    const [from, setFrom] = useState(`${today().slice(0, 8)}01`);
    const [to, setTo] = useState(today());
    const [who, setWho] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    async function print(agrodealerId: string, start: string, end: string, key: string) {
        setBusy(key); setError(null); setMessage(null);
        try {
            const report = await requestJson<AgrodealerReport>('POST', '/admin/reports/agrodealer', { agrodealer_id: agrodealerId, from: start, to: end });
            await printAgrodealerReport(report);
            setMessage(`Relatório de ${report.agrodealer.name} (${day(start)} a ${day(end)}) gerado e registado.`);
            router.reload({ only: ['prints'] });
        } catch (err) { setError(errorText(err)); } finally { setBusy(null); }
    }

    const people = useMemo(() => Array.from(new Set(prints.map((p) => p.printed_by ?? '—'))).sort(), [prints]);
    const visible = prints.filter((p) => !who || (p.printed_by ?? '—') === who);

    return (
        <AdminLayout>
            <Head title="Relatórios" />
            <div className="admin-page-heading"><div><div className="eyebrow">MEAL · RELATÓRIOS</div><h1>Relatórios</h1>
                <p>{user?.province ? `Comerciantes da província de ${user.province}.` : 'Relatório de transações de cada comerciante, e o registo de cada impressão.'}</p>
            </div></div>
            {error && <div className="error-box">{error}</div>}
            {message && <div className="success-box">{message}</div>}

            <section className="admin-card report-builder">
                <span className="section-label">Transações por comerciante (PDF)</span>
                <p className="muted-text small-note">Vendas do período, por produto e uma a uma, com a reconciliação em que já entraram. Os totais só contam vendas confirmadas. Fica registado quem imprimiu e quando.</p>
                <div className="report-form">
                    <label><span className="form-label">Comerciante</span>
                        <select className="input" value={dealerId} onChange={(e) => setDealerId(e.target.value)}>
                            <option value="">Escolher comerciante…</option>
                            {agrodealers.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.code})</option>)}
                        </select></label>
                    <label><span className="form-label">De</span><input className="input" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></label>
                    <label><span className="form-label">Até</span><input className="input" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
                    <button className="primary-button" disabled={!dealerId || !from || !to || busy !== null} onClick={() => void print(dealerId, from, to, 'new')}>
                        {busy === 'new' ? 'A gerar…' : 'Gerar PDF'}
                    </button>
                </div>
            </section>

            <div className="admin-section-head"><div>
                <span>{seesAllPrints ? 'Relatórios impressos' : 'Relatórios impressos na sua província'}</span>
                <small>{visible.length} impressão(ões)</small></div>
                {seesAllPrints && people.length > 1 && (
                    <select className="input report-who" aria-label="Impresso por" value={who} onChange={(e) => setWho(e.target.value)}>
                        <option value="">Todas as pessoas</option>
                        {people.map((p) => <option key={p} value={p}>{p}</option>)}
                    </select>
                )}
            </div>
            <div className="admin-table-card report-prints">
                {visible.map((p) => (
                    <div className="report-print-row" key={p.id}>
                        <span><strong>{p.agrodealer}</strong><small>{p.agrodealer_code}{p.province ? ` · ${p.province}` : ''} · {day(p.period_start)} a {day(p.period_end)}</small></span>
                        <span><strong>{p.printed_by ?? '—'}</strong><small>{p.printed_by_role ?? ''} · {formatDateTime(p.printed_at)}</small></span>
                        <span className="transaction-money"><strong>{formatMT(p.total_amount)}</strong><small>{p.transactions} venda(s) na altura</small></span>
                        <button className="quick-link" disabled={busy !== null} onClick={() => void print(p.agrodealer_id, p.period_start, p.period_end, p.id)}
                            title="Gera o PDF com os dados de hoje (fica registado como nova impressão)">{busy === p.id ? 'A gerar…' : 'Gerar de novo'}</button>
                    </div>
                ))}
                {visible.length === 0 && <div className="recent-empty">Ainda não foi impresso nenhum relatório.</div>}
            </div>
        </AdminLayout>
    );
}
