
import { useCallback, useEffect, useMemo, useState } from 'react';
import { AdminGate } from '../../components/admin-gate';
import { AdminHeader } from '../../components/admin-header';
import { HOUSEHOLD_ROLES, USER_ADMIN_ROLES, useAuth } from '../../auth-context';
import { ROLE_LABELS } from '../../components/user-menu';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { formatDateTime, formatMT } from '../../../lib/format';
import { type AgrodealerReport, printAgrodealerReport } from '../../../lib/agrodealer-report';

// Transactions report per agrodealer (PDF). Each print is recorded by the
// database (agrodealer_transactions_report); the MEAL manager sees every
// print, an officer those of the province.

type Dealer = { id: string; code: string; name: string };
type Print = {
  id: string; printed_at: string; printed_by: string | null; printed_by_role: string | null;
  agrodealer_id: string; agrodealer: string; agrodealer_code: string; province: string | null;
  period_start: string; period_end: string; transactions: number; total_amount: number;
  voucher_amount: number; copayment_amount: number;
};

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Maputo' });
const day = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;

function ReportsScreen() {
  const auth = useAuth();
  const isManager = USER_ADMIN_ROLES.includes(auth.operator?.role ?? '');
  const [dealers, setDealers] = useState<Dealer[]>([]);
  const [dealerId, setDealerId] = useState('');
  const [from, setFrom] = useState(today().slice(0, 8) + '01');
  const [to, setTo] = useState(today());
  const [prints, setPrints] = useState<Print[] | null>(null);
  const [who, setWho] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const loadPrints = useCallback(() => {
    callRpc<Print[]>('list_report_prints', {}).then(setPrints).catch((err) => setError(errorMessage(err)));
  }, []);

  useEffect(() => {
    callRpc<Dealer[]>('list_agrodealer_options', {}).then(setDealers).catch((err) => setError(errorMessage(err)));
    loadPrints();
  }, [loadPrints]);

  async function print(agrodealerId: string, start: string, end: string, key: string) {
    setBusy(key); setError(null); setMessage(null);
    try {
      const report = await callRpc<AgrodealerReport>('agrodealer_transactions_report', { p_agrodealer_id: agrodealerId, p_from: start, p_to: end });
      await printAgrodealerReport(report);
      setMessage(`Relatório de ${report.agrodealer.name} (${day(start)} a ${day(end)}) gerado e registado.`);
      loadPrints();
    } catch (err) { setError(errorMessage(err)); } finally { setBusy(null); }
  }

  const people = useMemo(() => Array.from(new Set((prints ?? []).map((p) => p.printed_by ?? '—'))).sort(), [prints]);
  const visible = (prints ?? []).filter((p) => !who || (p.printed_by ?? '—') === who);

  return (
    <main className="admin-shell">
      <AdminHeader />
      <div className="admin-page-heading"><div><div className="eyebrow">MEAL · RELATÓRIOS</div><h1>Relatórios</h1>
        <p>{auth.operator?.provinceName ? `Agrodealers da província de ${auth.operator.provinceName}.` : 'Relatório de transações de cada agrodealer, e o registo de cada impressão.'}</p>
      </div></div>
      {error && <div className="error-box">{error}</div>}
      {message && <div className="success-box">{message}</div>}

      <section className="admin-card report-builder">
        <span className="section-label">Transações por agrodealer (PDF)</span>
        <p className="muted-text small-note">Vendas do período, por produto e uma a uma, com a reconciliação em que já entraram. Os totais só contam vendas confirmadas. Fica registado quem imprimiu e quando.</p>
        <div className="report-form">
          <label><span className="form-label">Agrodealer</span>
            <select className="input" value={dealerId} onChange={(e) => setDealerId(e.target.value)}>
              <option value="">Escolher agrodealer…</option>
              {dealers.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.code})</option>)}
            </select></label>
          <label><span className="form-label">De</span><input className="input" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></label>
          <label><span className="form-label">Até</span><input className="input" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
          <button className="primary-button" disabled={!dealerId || !from || !to || busy !== null} onClick={() => void print(dealerId, from, to, 'new')}>
            {busy === 'new' ? 'A gerar…' : 'Gerar PDF'}
          </button>
        </div>
      </section>

      <div className="admin-section-head"><div>
        <span>{isManager ? 'Relatórios impressos' : 'Relatórios impressos na sua província'}</span>
        <small>{visible.length} impressão(ões)</small></div>
        {isManager && people.length > 1 && <select className="input report-who" aria-label="Impresso por" value={who} onChange={(e) => setWho(e.target.value)}>
          <option value="">Todas as pessoas</option>
          {people.map((p) => <option key={p} value={p}>{p}</option>)}
        </select>}
      </div>
      {prints === null && !error && <div className="loading-card"><span className="loading-dot" /> A carregar…</div>}
      {prints && <div className="admin-table-card report-prints">
        {visible.map((p) => <div className="report-print-row" key={p.id}>
          <span><strong>{p.agrodealer}</strong><small>{p.agrodealer_code}{p.province ? ` · ${p.province}` : ''} · {day(p.period_start)} a {day(p.period_end)}</small></span>
          <span><strong>{p.printed_by ?? '—'}</strong><small>{ROLE_LABELS[p.printed_by_role ?? ''] ?? ''} · {formatDateTime(p.printed_at)}</small></span>
          <span className="transaction-money"><strong>{formatMT(p.total_amount)}</strong><small>{p.transactions} venda(s) na altura</small></span>
          <button className="quick-link" disabled={busy !== null} onClick={() => void print(p.agrodealer_id, p.period_start, p.period_end, p.id)}
            title="Gera o PDF com os dados de hoje (fica registado como nova impressão)">{busy === p.id ? 'A gerar…' : 'Gerar de novo'}</button>
        </div>)}
        {visible.length === 0 && <div className="recent-empty">Ainda não foi impresso nenhum relatório.</div>}
      </div>}
    </main>
  );
}

export default function ReportsPage() {
  return <AdminGate roles={HOUSEHOLD_ROLES} deniedCode="REPORT_NOT_ALLOWED"><ReportsScreen /></AdminGate>;
}
