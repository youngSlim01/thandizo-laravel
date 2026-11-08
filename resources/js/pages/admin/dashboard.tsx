
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AdminHeader, UserMenu } from '@/components/admin-layout';
import { MozambiqueMap, type ProvinceFigure } from '@/components/mozambique-map';
import { formatDateTime, formatMT } from '@/lib/format';
import type { SharedProps } from '@/types';

type DashboardData = {
  month: string;
  current_month: string;
  months: string[];
  kpis: {
    households: number; households_new: number; households_new_prev: number;
    vouchers: number; vouchers_prev: number; transactions: number; transactions_prev: number; agrodealers: number;
  };
  values: {
    voucher_amount: number; copayment_amount: number; total_amount: number;
    voucher_amount_prev: number; copayment_amount_prev: number; total_amount_prev: number;
  };
  daily: { day: string; vouchers: number; transactions: number }[];
  by_province: ProvinceFigure[];
  households_without_province: number;
  by_agrodealer: { name: string; transactions: number; total_amount: number }[];
  by_category: { category: string; total_amount: number }[];
  latest: {
    transaction_id: string; transaction_code: string; confirmed_at: string;
    representative_name: string | null; agrodealer: string | null; province: string | null;
    total_amount: number; voucher_amount: number; copayment_amount: number;
  }[];
};

function monthLabel(key: string) {
  return new Date(key + '-01T12:00:00').toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' }).replace(/^./, x => x.toUpperCase());
}
function pct(current: number, previous: number) {
  if (!previous) return current ? 100 : 0;
  return Math.round(((current - previous) / previous) * 100);
}
function shortDay(day: string) { return day.slice(8, 10); }

function Icon({ name }: { name: 'calendar' | 'users' | 'wallet' | 'cart' | 'store' | 'coins' | 'info' }) {
  const common = { width: 22, height: 22, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
  const paths = {
    calendar: <><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M7 2v4M17 2v4M3 9h18"/></>,
    users: <><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.4"/><path d="M3 20a6 6 0 0 1 12 0M14 17a5 5 0 0 1 7 3"/></>,
    wallet: <><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H20v14H5.5A2.5 2.5 0 0 1 3 16.5v-9Z"/><path d="M3 8h17"/><path d="M16 12h5v4h-5a2 2 0 1 1 0-4Z"/></>,
    cart: <><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/><path d="M3 4h2l2.2 10.5h10.9L21 7H6"/></>,
    store: <><path d="M4 10v10h16V10"/><path d="M3 10 5 4h14l2 6"/><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M9 20v-5h6v5"/></>,
    coins: <><ellipse cx="8" cy="7" rx="5" ry="2.5"/><path d="M3 7v5c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5V7"/><path d="M13 9.5c2.8 0 5 1.1 5 2.5v5c0 1.4-2.2 2.5-5 2.5s-5-1.1-5-2.5v-2.5"/></>,
    info: <><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></>,
  };
  return <svg {...common}>{paths[name]}</svg>;
}

// Daily chart: grid lines, axis labels and points share one scale (0 at the
// bottom line, the top tick at the top line). Points are drawn as round caps,
// so they stay round however wide the card is.
function DailyChart({ days, label }: { days: { day: string; value: number }[]; label: string }) {
  const top = Math.max(4, Math.ceil(Math.max(0, ...days.map((d) => d.value)) / 4) * 4);
  const ticks = [0, top / 4, top / 2, (top * 3) / 4, top];
  const n = Math.max(days.length - 1, 1);
  const pts = days.map((d, i) => [(i / n) * 100, 100 - (d.value / top) * 100] as const);
  const line = pts.map((p) => p.join(',')).join(' ');
  const every = Math.max(1, Math.ceil(days.length / 8));
  return (
    <div className="daily-chart" role="img" aria-label={label}>
      <div className="daily-chart-plot">
        {ticks.map((t) => <div key={t} className="daily-chart-grid" style={{ top: `${100 - (t / top) * 100}%` }}><span>{t}</span></div>)}
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
          <polygon points={`0,100 ${line} 100,100`} className="daily-chart-area" />
          <polyline points={line} className="daily-chart-line" />
          {pts.map(([x, y], i) => days[i].value > 0 && (
            <line key={days[i].day} x1={x} y1={y} x2={x} y2={y} className="daily-chart-dot"><title>{`${shortDay(days[i].day)}: ${days[i].value}`}</title></line>
          ))}
        </svg>
      </div>
      <div className="daily-chart-x">
        {days.map((d, i) => i % every === 0 && <span key={d.day} style={{ left: `${(i / n) * 100}%` }}>{shortDay(d.day)}</span>)}
      </div>
    </div>
  );
}



// Painel MEAL de um mês. Os números vêm calculados do servidor
// (ReportService::mealDashboard); mudar de mês volta a pedir só o painel.
export default function MealDashboard({ dashboard: data }: { dashboard: DashboardData }) {
  const user = usePage<SharedProps>().props.auth.user;
  const [loading, setLoading] = useState(false);
  const setSelectedMonth = (month: string) => router.get('/admin/dashboard', { month }, {
    only: ['dashboard'], preserveState: true, preserveScroll: true, replace: true,
    onStart: () => setLoading(true), onFinish: () => setLoading(false),
  });
  // Per-province figures come computed from the database (active households,
  // cards delivered, sales of the month); never count 20,000 rows here.
  const mapFigures: ProvinceFigure[] = data?.by_province ?? [];

  const categoryData = useMemo(() => {
    const total = (data?.by_category ?? []).reduce((sum, item) => sum + Number(item.total_amount || 0), 0);
    let cursor = 0;
    return (data?.by_category ?? []).map((item, i) => {
      const value = Number(item.total_amount || 0);
      const share = total ? value / total * 100 : 0;
      const itemData = { name: item.category, value, pct: Math.round(share), start: cursor, color: ['#17815b','#f5b51b','#2089df','#9b7bd4','#e87955'][i % 5] };
      cursor += share;
      return itemData;
    });
  }, [data]);
  const dealerData = data?.by_agrodealer ?? [];
  const maxDealer = Math.max(...dealerData.map(x => x.transactions), 1);
  const kpis = [
    { label: 'Beneficiários registados', value: (data?.kpis.households ?? 0).toLocaleString('pt-PT'), change: pct(data?.kpis.households_new ?? 0, data?.kpis.households_new_prev ?? 0), tone: 'green', icon: 'users' as const, caption: 'novos registos ' },
    { label: 'Cartões entregues', value: (data?.kpis.vouchers ?? 0).toLocaleString('pt-PT'), change: pct(data?.kpis.vouchers ?? 0, data?.kpis.vouchers_prev ?? 0), tone: 'blue', icon: 'wallet' as const, caption: '' },
    { label: 'Transações confirmadas', value: (data?.kpis.transactions ?? 0).toLocaleString('pt-PT'), change: pct(data?.kpis.transactions ?? 0, data?.kpis.transactions_prev ?? 0), tone: 'gold', icon: 'cart' as const, caption: '' },
    { label: 'Comerciantes activos', value: (data?.kpis.agrodealers ?? 0).toLocaleString('pt-PT'), change: null, tone: 'purple', icon: 'store' as const, caption: '' },
  ];
  const daily = data?.daily ?? [];
  const totalVoucher = Number(data?.values.voucher_amount ?? 0);
  const totalCopay = Number(data?.values.copayment_amount ?? 0);
  const totalValue = Number(data?.values.total_amount ?? 0);
  const previousVoucherValue = Number(data?.values.voucher_amount_prev ?? 0);
  const previousCopayValue = Number(data?.values.copayment_amount_prev ?? 0);
  const previousTotalValue = Number(data?.values.total_amount_prev ?? 0);
  const month = data.month;

  return <div className="meal-dashboard-app">
    <Head title="Painel MEAL" />
    <AdminHeader topbar={false}/>
    <main className="meal-dashboard-main">
      <div className="meal-dashboard-heading">
        <div><h1>Olá, {user?.full_name ?? ''}</h1><p>{user?.province ? `Visão geral da província de ${user.province}` : 'Visão geral da implementação do e-voucher'}</p></div>
        <div className="meal-dashboard-user">
          <div className="meal-month-select"><Icon name="calendar"/><select value={month} onChange={e=>setSelectedMonth(e.target.value)} aria-label="Mês do dashboard">
            {(data?.months ?? []).map(m=><option key={m} value={m}>{monthLabel(m)}</option>)}
          </select></div>
          <UserMenu />
        </div>
      </div>
      {loading ? <div className="meal-loading">A carregar dashboard...</div> : data && <>
        <section className="meal-top-kpis">
          {kpis.map(k=><div key={k.label} className={'meal-top-kpi '+k.tone}><div className="meal-kpi-icon"><Icon name={k.icon}/></div><div><span>{k.label}</span><strong>{k.value}</strong>{k.change!==null&&<small className={k.change>0?'up':k.change<0?'down':'flat'}>{k.change>0?'↑':k.change<0?'↓':'='} {Math.abs(k.change)}% <em>{k.caption}vs. mês anterior</em></small>}</div></div>)}
        </section>
        <section className="meal-middle-grid meal-middle-grid-2">
          <div className="meal-processed-panel"><div className="meal-panel-title">Valor processado (MT) <Icon name="info"/></div><div className="meal-processed-cards">
            <div><Icon name="wallet"/><span>Valor voucher</span><strong>{formatMT(totalVoucher)}</strong><small className={pct(totalVoucher,previousVoucherValue)>=0?'up':'down'}>{pct(totalVoucher,previousVoucherValue)>0?'↑':pct(totalVoucher,previousVoucherValue)<0?'↓':'='} {Math.abs(pct(totalVoucher,previousVoucherValue))}%</small></div>
            <div><Icon name="coins"/><span>Copagamento</span><strong>{formatMT(totalCopay)}</strong><small className={pct(totalCopay,previousCopayValue)>=0?'up':'down'}>{pct(totalCopay,previousCopayValue)>0?'↑':pct(totalCopay,previousCopayValue)<0?'↓':'='} {Math.abs(pct(totalCopay,previousCopayValue))}%</small></div>
            <div><Icon name="wallet"/><span>Valor total</span><strong>{formatMT(totalValue)}</strong><small className={pct(totalValue,previousTotalValue)>=0?'up':'down'}>{pct(totalValue,previousTotalValue)>0?'↑':pct(totalValue,previousTotalValue)<0?'↓':'='} {Math.abs(pct(totalValue,previousTotalValue))}%</small></div>
          </div></div>
          <div className="meal-daily-panel meal-daily-wide"><div className="meal-panel-title">Transações (diário)</div><DailyChart days={daily.map((x) => ({ day: x.day, value: Number(x.transactions) }))} label="Transações confirmadas por dia" /></div>
        </section>
        <section className="meal-lower-grid">
          <div className="meal-panel"><h2>Beneficiários por província</h2><p className="meal-panel-subtitle">Agregados ativos. O mapa destaca apenas as províncias com beneficiários.</p>
            <MozambiqueMap figures={mapFigures} withoutProvince={data.households_without_province}/>
          </div>
          <div className="meal-panel"><h2>Vendas por categoria de insumo</h2><div className="meal-donut-wrap">
            {categoryData.length ? <><div className="meal-donut-ring"><svg className="meal-donut" viewBox="0 0 42 42"><circle cx="21" cy="21" r="15.5" fill="none" stroke="#eef1f3" strokeWidth="7"/>{categoryData.map(x=><circle key={x.name} cx="21" cy="21" r="15.5" fill="none" stroke={x.color} strokeWidth="7" pathLength="100" strokeDasharray={(x.value/(categoryData.reduce((s,y)=>s+y.value,0)||1)*100)+' '+(100-(x.value/(categoryData.reduce((s,y)=>s+y.value,0)||1)*100))} strokeDashoffset={-x.start} transform="rotate(-90 21 21)"/>)}</svg><div className="meal-donut-center"><strong>{formatMT(totalValue)}</strong><span>Vendas do mês</span></div></div><div className="meal-donut-legend">{categoryData.map(x=><div key={x.name}><i style={{background:x.color}}/><span>{x.name}</span><strong>{x.pct}%</strong></div>)}</div></> : <div className="meal-empty">Sem vendas por categoria no período.</div>}
          </div></div>
          <div className="meal-panel"><h2>Transações por comerciante</h2><div className="meal-bars">{dealerData.map(d=><div className="meal-bar-row" key={d.name}><span>{d.name}</span><div><i style={{width:(d.transactions/maxDealer*100)+'%'}}/></div><strong>{d.transactions}</strong></div>)}{dealerData.length===0&&<div className="meal-empty">Sem transações no período.</div>}</div></div>
        </section>
        <section className="meal-latest"><div className="meal-latest-head"><h2>Últimas transações registadas</h2><Link href="/admin/transactions">Ver todas →</Link></div><div className="meal-table-wrap"><table><thead><tr><th>Data/Hora</th><th>Referência</th><th>Beneficiário</th><th>Comerciante</th><th>Província</th><th>Valor total (MT)</th><th>Voucher (MT)</th><th>Copagamento (MT)</th><th>Estado</th></tr></thead><tbody>{data.latest.map(t=><tr key={t.transaction_id}><td>{formatDateTime(t.confirmed_at)}</td><td>{t.transaction_code}</td><td>{t.representative_name||'Beneficiário'}</td><td>{t.agrodealer||'—'}</td><td>{t.province||'—'}</td><td>{formatMT(t.total_amount)}</td><td>{formatMT(t.voucher_amount)}</td><td>{formatMT(t.copayment_amount)}</td><td><span className="meal-confirmed">Confirmada</span></td></tr>)}</tbody></table>{data.latest.length===0&&<div className="meal-empty">Ainda não existem transações confirmadas.</div>}</div></section>
      </>}
    </main>
  </div>;
}
