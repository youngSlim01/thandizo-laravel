
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { FinanceGate } from '../components/finance-gate';
import { FinanceHeader } from '../components/finance-header';
import { callRpc } from '../../lib/supabase';
import { errorMessage } from '../../lib/errors';
import { formatMT, formatDateTime } from '../../lib/format';

// Every figure comes from finance_dashboard_month, computed in the database
// (agrodealers have no "province" column; adding sales up in the browser
// stops at 1,000 rows).
type Settlement = {
  id: string;
  settlement_code: string;
  agrodealer_id: string;
  agrodealer: string | null;
  province: string | null;
  period_start: string;
  period_end: string;
  transaction_count: number;
  gross_amount: number;
  voucher_amount: number;
  copayment_amount: number;
  payable_amount: number;
  status: string;
  submitted_at: string | null;
  created_at: string;
};

type MonthTrend = { month: string; voucher_amount: number; copayment_amount: number; transactions: number; agrodealers: number };
type FinanceMonth = { month: string; months: string[]; trend: MonthTrend[]; agrodealers_active: number; provinces: string[]; settlements: Settlement[] };

const dealerName = (s: Settlement) => s.agrodealer ?? 'Agrodealer';
const dealerProvince = (s: Settlement) => s.province ?? '—';

const money = (n: number) => formatMT(Number(n || 0));

function monthLabel(key: string) {
  return new Date(key + '-01T12:00:00').toLocaleDateString('pt-PT', {
    month: 'long',
    year: 'numeric',
  }).replace(/^./, (x) => x.toUpperCase());
}

function monthShort(key: string) {
  return new Date(key + '-01T12:00:00').toLocaleDateString('pt-PT', { month: 'short' }).replace('.', '');
}

function pctChange(current: number, previous: number) {
  if (!previous) return current ? 100 : 0;
  return Math.round(((current - previous) / previous) * 100);
}

function Icon({ name }: { name: 'wallet' | 'people' | 'receipt' | 'store' | 'grid' | 'reconcile' | 'transactions' | 'reports' | 'user' | 'lock' | 'logout' | 'calendar' | 'search' | 'download' | 'eye' }) {
  const common = { width: 21, height: 21, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
  const paths: Record<string, ReactNode> = {
    wallet: <><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H20v14H5.5A2.5 2.5 0 0 1 3 16.5v-9Z"/><path d="M3 8h17"/><path d="M16 12h5v4h-5a2 2 0 1 1 0-4Z"/></>,
    people: <><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M14 16a5 5 0 0 1 7 4"/></>,
    receipt: <><path d="M6 3h12v18l-2.5-1.7L13 21l-2.5-1.7L8 21l-2-1.5V3Z"/><path d="M9 8h6M9 12h6M9 16h4"/></>,
    store: <><path d="M4 10v10h16V10"/><path d="M3 10 5 4h14l2 6"/><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M9 20v-5h6v5"/></>,
    grid: <><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></>,
    reconcile: <><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/><path d="m15 16 2 2 4-4"/></>,
    transactions: <><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></>,
    reports: <><path d="M5 3h10l4 4v14H5z"/><path d="M15 3v5h5M8 17v-4M12 17v-7M16 17v-3"/></>,
    user: <><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/></>,
    lock: <><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></>,
    logout: <><path d="M10 4H5v16h5M14 8l4 4-4 4M9 12h9"/></>,
    calendar: <><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M7 2v4M17 2v4M3 9h18"/></>,
    search: <><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></>,
    download: <><path d="M12 3v12M7 11l5 5 5-5M4 21h16"/></>,
    eye: <><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></>,
  };
  return <svg {...common}>{paths[name]}</svg>;
}

function Trend({ values, tone }: { values: number[]; tone: 'green' | 'blue' | 'gold' | 'purple' }) {
  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const span = max - min || 1;
  const points = values.map((v, i) => {
    const x = 5 + (i * 90) / Math.max(values.length - 1, 1);
    const y = 36 - ((v - min) / span) * 28;
    return [x, y] as const;
  });
  const line = points.map(([x, y]) => x + ',' + y).join(' ');
  const area = '5,40 ' + line + ' 95,40';
  return (
    <svg className={'finance-trend finance-trend-' + tone} viewBox="0 0 100 42" preserveAspectRatio="none" aria-hidden="true">
      <polygon points={area} className="trend-area" />
      <polyline points={line} className="trend-line" />
      {points.map(([x, y], i) => <circle key={i} cx={x} cy={y} r="1.8" className="trend-dot" />)}
    </svg>
  );
}

export default function FinanceDashboardPage() {
  const [data, setData] = useState<FinanceMonth | null>(null);
  const [selectedMonth, setSelectedMonth] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [province, setProvince] = useState('ALL');
  const [status, setStatus] = useState('ALL');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError(null);
    callRpc<FinanceMonth>('finance_dashboard_month', { p_month: selectedMonth })
      .then((d) => { if (!cancelled) setData(d); })
      .catch((e) => { if (!cancelled) setError(errorMessage(e)); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [selectedMonth]);

  const settlements = data?.settlements ?? [];
  const monthOptions = data?.months ?? [];
  const month = data?.month ?? '';
  const trendMonths = (data?.trend ?? []).map((t) => t.month);
  const monthly = (data?.trend ?? []).map((t) => ({
    month: t.month, voucher: Number(t.voucher_amount), copayment: Number(t.copayment_amount),
    transactions: Number(t.transactions), dealers: Number(t.agrodealers),
  }));

  const current = monthly[monthly.length - 1] ?? { month, voucher: 0, copayment: 0, transactions: 0, dealers: 0 };
  const previous = monthly[monthly.length - 2] ?? current;

  const visibleSettlements = useMemo(() => settlements.filter(s => {
    const p = dealerProvince(s);
    const statusMatch = status === 'ALL' || s.status === status;
    const provinceMatch = province === 'ALL' || p === province;
    const term = query.trim().toLowerCase();
    const searchMatch = !term || s.settlement_code.toLowerCase().includes(term) || dealerName(s).toLowerCase().includes(term) || p.toLowerCase().includes(term);
    return statusMatch && provinceMatch && searchMatch;
  }), [settlements, status, province, query]);

  const provinces = [...(data?.provinces ?? [])].sort();

  const kpis = [
    { label: 'Valor a liquidar (vouchers)', value: money(current.voucher), change: pctChange(current.voucher, previous.voucher), tone: 'green' as const, icon: 'wallet' as const, values: monthly.map(x => x.voucher) },
    { label: 'Copagamento dos beneficiários', value: money(current.copayment), change: pctChange(current.copayment, previous.copayment), tone: 'blue' as const, icon: 'people' as const, values: monthly.map(x => x.copayment) },
    { label: 'Transações registadas', value: current.transactions.toLocaleString('pt-PT'), change: pctChange(current.transactions, previous.transactions), tone: 'gold' as const, icon: 'receipt' as const, values: monthly.map(x => x.transactions) },
    { label: 'Agrodealeres activos', value: (data?.agrodealers_active ?? 0).toLocaleString('pt-PT'), change: pctChange(current.dealers, previous.dealers), tone: 'purple' as const, icon: 'store' as const, values: monthly.map(x => x.dealers) },
  ];

  return (
    <FinanceGate>
      <div className="finance-app">
        <FinanceHeader />
        <main className="finance-main">
          <div className="finance-topbar">
            <div>
              <h1>Financeiro</h1>
              <p>Confirmação e validação das reconciliações do MEAL</p>
            </div>
            <div className="finance-period">
              <Icon name="calendar" />
              <select aria-label="Mês" value={month} onChange={e => setSelectedMonth(e.target.value)}>
                {monthOptions.map(m => <option key={m} value={m}>{monthLabel(m)}</option>)}
              </select>
            </div>
          </div>

          {error && <div className="error-box">{error}</div>}

          <section className="finance-kpis">
            {kpis.map(k => (
              <div className={'finance-kpi ' + k.tone} key={k.label}>
                <div className="finance-kpi-head">
                  <span className="finance-kpi-icon"><Icon name={k.icon} /></span>
                  <span>{k.label}</span>
                </div>
                <strong>{k.value}</strong>
                <small className={k.change >= 0 ? 'positive' : 'negative'}>{k.change >= 0 ? '↑' : '↓'} {Math.abs(k.change)}% <em>vs. mês anterior</em></small>
                <div className="finance-kpi-chart-title">{k.label.replace(' (vouchers)', '')}</div>
                <Trend values={k.values} tone={k.tone} />
                <div className="finance-months">{trendMonths.map(m => <span key={m}>{monthShort(m)}</span>)}</div>
              </div>
            ))}
          </section>

          <section className="finance-reconciliations">
            <div className="finance-section-title">
              <h2>Reconciliações por agrodealer</h2>
              <span>{visibleSettlements.length} registo(s)</span>
            </div>

            <div className="finance-table-tools">
              <div className="finance-search">
                <Icon name="search" />
                <input value={query} onChange={e => setQuery(e.target.value)} placeholder="Pesquisar por agrodealer, referência ou província..." />
              </div>
              <select value={province} onChange={e => setProvince(e.target.value)}>
                <option value="ALL">Todas as províncias</option>
                {provinces.map(p => <option key={p} value={p}>{p}</option>)}
              </select>
              <select value={status} onChange={e => setStatus(e.target.value)}>
                <option value="ALL">Todos os estados</option>
                <option value="SUBMITTED_TO_FINANCE">Aguardando</option>
                <option value="VALIDATED">Validadas</option>
                <option value="RETURNED">Devolvidas</option>
              </select>
              <button className="finance-export" onClick={() => window.print()}><Icon name="download" /> Exportar</button>
            </div>

            <div className="finance-table-wrap">
              <table className="finance-table">
                <thead>
                  <tr>
                    <th>Agrodealer</th><th>Província</th><th>Período</th><th>Transações</th>
                    <th>Valor voucher<br />(MT)</th><th>Copagamento<br />(MT)</th><th>Valor a liquidar<br />(MT)</th><th>Estado</th><th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {loading ? <tr><td colSpan={9} className="finance-empty">A carregar...</td></tr> :
                    visibleSettlements.slice(0, 10).map(s => (
                      <tr key={s.id}>
                        <td><strong>{dealerName(s)}</strong></td>
                        <td>{dealerProvince(s)}</td>
                        <td>{s.period_start.slice(8, 10)}/{s.period_start.slice(5, 7)} - {s.period_end.slice(8, 10)}/{s.period_end.slice(5, 7)}</td>
                        <td>{s.transaction_count}</td>
                        <td>{money(s.voucher_amount)}</td>
                        <td>{money(s.copayment_amount)}</td>
                        <td><strong>{money(s.payable_amount)}</strong></td>
                        <td><span className={'finance-status ' + s.status.toLowerCase()}>{s.status === 'VALIDATED' ? 'Validada' : s.status === 'RETURNED' ? 'Devolvida' : s.status === 'SUBMITTED_TO_FINANCE' ? 'Aguardando' : 'Não submetida'}</span></td>
                        <td><Link className="finance-view" href={'/finance/settlements?settlement=' + s.id} aria-label="Ver reconciliação"><Icon name="eye" /></Link></td>
                      </tr>
                    ))}
                  {!loading && visibleSettlements.length === 0 && <tr><td colSpan={9} className="finance-empty">Nenhuma reconciliação encontrada para este período.</td></tr>}
                </tbody>
              </table>
            </div>
            <div className="finance-table-foot">
              <span>A mostrar {Math.min(10, visibleSettlements.length)} de {visibleSettlements.length} reconciliações</span>
              <Link href="/finance/settlements">Ver todas as reconciliações →</Link>
            </div>
          </section>
        </main>
      </div>
    </FinanceGate>
  );
}
