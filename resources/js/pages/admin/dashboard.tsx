import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminHeader, NotificationBell, UserMenu } from '@/components/admin-layout';
import { type Alert, AlertList, Bar, Donut, Icon, number, pct, Pill, share } from '@/components/dashboard-ui';
import { MozambiqueMap, type ProvinceFigure } from '@/components/mozambique-map';
import { formatDate, formatDateTime, formatMT } from '@/lib/format';
import type { SharedProps } from '@/types';

type UsagePoint = { day: number; share: number };
type District = {
  district_id: string; district: string; province: string; target: number | null; households: number; bought: number; missing: number;
  fair_starts_on: string | null; fair_ends_on: string | null; fair_status: 'none' | 'planned' | 'ongoing' | 'finished';
};
type LiveFair = District & { day: number; days: number; days_left: number; bought_today: number; bought_yesterday: number; hours: number[] };
type Dashboard = {
  cycles: { id: string; code: string; name: string; status: 'OPEN' | 'CLOSED' }[];
  province: string | null;
  alerts: Alert[];
  activity: { action: string; text: string; at: string; actor: string | null }[];
  cycle: null | {
    id: string; code: string; name: string; description: string | null; status: 'OPEN' | 'CLOSED'; starts_on: string | null; ends_on: string | null;
    closed_at: string | null; day: number; total_days: number | null; days_left: number | null; time_share: number | null;
  };
  districts?: District[];
  live_fairs?: LiveFair[];
  kpis?: {
    target: number | null; households: number; active_cards: number; allocated: number; used: number; used_share: number; unused: number;
    not_bought: number; not_bought_value: number; unpaid: number; copayment: number; transactions: number;
  };
  usage?: { days: UsagePoint[]; previous: { code: string; name: string; days: UsagePoint[] } | null };
  by_tier?: { tier: string; amount: number; households: number; bought: number; generated_codes: number; allocated: number; used: number }[];
  by_sex?: { sex: 'F' | 'M'; households: number; bought: number; average_spent: number }[];
  substitutes?: number;
  by_province?: ProvinceFigure[];
  not_bought_by_community?: { community: string; district: string | null; households: number; not_bought: number; unused: number }[];
  by_agrodealer?: { name: string; code: string; transactions: number; voucher_amount: number; to_reconcile: number; in_approval: number; validated: number }[];
};

const FAIR_STATUS: Record<District['fair_status'], string> = { none: 'Por marcar', planned: 'Marcada', ongoing: 'A decorrer', finished: 'Concluída' };
const missingHref = (cycle: string, district: string) => `/admin/dashboard/faltosos?cycle=${cycle}&district=${district}`;

// Ritmo do ciclo: valor usado acumulado (% do atribuído), dia a dia, e o
// ciclo anterior nos mesmos dias.
function UsageChart({ usage, totalDays }: { usage: NonNullable<Dashboard['usage']>; totalDays: number | null }) {
  const days = Math.max(totalDays ?? 0, usage.days.length, usage.previous?.days.length ?? 0, 2);
  const top = Math.max(1, ...usage.days.map((d) => d.share), ...(usage.previous?.days.map((d) => d.share) ?? []));
  const x = (day: number) => ((day - 1) / (days - 1)) * 100;
  const y = (s: number) => 100 - (s / top) * 100;
  const line = (points: UsagePoint[]) => points.map((p) => `${x(p.day)},${y(p.share)}`).join(' ');
  const last = usage.days[usage.days.length - 1];
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * top);
  return (
    <div className="cd-chart" role="img" aria-label={`Valor usado: ${pct(last?.share ?? 0)} no dia ${last?.day ?? 0}`}>
      <div className="cd-chart-plot">
        {ticks.map((t) => <div key={t} className="cd-chart-grid" style={{ top: `${y(t)}%` }}><span>{pct(t)}</span></div>)}
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
          {usage.previous && <polyline points={line(usage.previous.days)} className="cd-chart-previous" />}
          <polyline points={line(usage.days)} className="cd-chart-current" />
        </svg>
        {last && <span className="cd-chart-pill" style={{ left: `${x(last.day)}%`, top: `${y(last.share)}%` }}>{pct(last.share)}</span>}
      </div>
      <div className="cd-chart-x"><span>dia 1</span><span>dia {days}</span></div>
    </div>
  );
}

type SetupStep = { key: string; title: string; text: string; href: string; done: boolean };

// Primeiros passos para montar o programa: cada um marca-se sozinho quando os
// dados existem; a lista desaparece quando todos estão feitos.
function SetupSteps({ steps }: { steps: SetupStep[] }) {
  const done = steps.filter((s) => s.done).length;
  const next = steps.find((s) => !s.done);
  return (
    <section className="cd-panel cd-setup">
      <div className="cd-panel-head">
        <span className="cd-badge cd-badge-green"><Icon name="list" /></span>
        <h2>Primeiros passos para montar o programa</h2>
        <span className="cd-setup-count">{done} de {steps.length} feitos</span>
      </div>
      <Bar value={steps.length ? done / steps.length : 0} />
      <ol className="cd-setup-list">
        {steps.map((s, i) => (
          <li key={s.key} className={s.done ? 'is-done' : s === next ? 'is-next' : ''}>
            <b aria-hidden="true">{s.done ? '✓' : i + 1}</b>
            <div><strong>{s.title}</strong><span>{s.text}</span></div>
            {s === next
              ? <Link href={s.href} className="primary-button cd-setup-go">Fazer agora →</Link>
              : <Link href={s.href} className="cd-link">{s.done ? 'Rever' : 'Abrir'}</Link>}
          </li>
        ))}
      </ol>
    </section>
  );
}

// Feira a decorrer: quem já comprou, ontem e hoje, e compras por hora.
function LiveFairPanel({ fair, cycleId }: { fair: LiveFair; cycleId: string }) {
  const hours = fair.hours.map((n, h) => ({ h, n })).filter(({ h }) => h >= 6 && h <= 18);
  const top = Math.max(1, ...hours.map((x) => x.n));
  return (
    <div className="cd-panel">
      <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="calendar" /></span>
        <h2>Feira a decorrer: {fair.district}</h2><span className="cd-setup-count">dia {fair.day} de {fair.days}</span></div>
      <div className="md-live">
        <div>
          <div className="md-big">{number(fair.bought)} <small>de {number(fair.households)} agregados compraram</small></div>
          <Bar value={share(fair.bought, fair.households)} />
          <p className="cd-note">Ontem {number(fair.bought_yesterday)} · hoje {number(fair.bought_today)} até agora · faltam {number(fair.missing)}</p>
          <a href={missingHref(cycleId, fair.district_id)} className="primary-button md-go">Lista de quem falta ({number(fair.missing)}) →</a>
        </div>
        <div>
          <div className="cd-note">Compras por hora, hoje</div>
          <div className="md-hours" role="img" aria-label="Compras por hora">
            {hours.map(({ h, n }) => <div key={h}><i style={{ height: `${(n / top) * 100}%` }} title={`${h}h: ${n}`} /><span>{h}h</span></div>)}
          </div>
        </div>
      </div>
    </div>
  );
}

// Painel MEAL do dia-a-dia, por ciclo (CycleDashboardService): metas e
// feiras por distrito, a feira a decorrer, alertas por gravidade. Mudar de
// ciclo volta a pedir só o painel.
export default function MealDashboard({ dashboard: data, setup }: { dashboard: Dashboard; setup: SetupStep[] }) {
  const user = usePage<SharedProps>().props.auth.user;
  const [loading, setLoading] = useState(false);
  const chooseCycle = (cycle: string) => router.get('/admin/dashboard', { cycle }, {
    only: ['dashboard'], preserveState: true, preserveScroll: true, replace: true,
    onStart: () => setLoading(true), onFinish: () => setLoading(false),
  });
  const cycle = data.cycle;
  const k = data.kpis;
  const firstName = (user?.full_name ?? '').split(' ')[0];
  const live = data.live_fairs ?? [];
  const districts = data.districts ?? [];
  const sex = data.by_sex ?? [];
  const women = sex.find((s) => s.sex === 'F');
  const totalSex = sex.reduce((n, s) => n + s.households, 0);
  const dealers = data.by_agrodealer ?? [];
  const dealerTotal = dealers.reduce((t, a) => ({ n: t.n + a.transactions, v: t.v + a.voucher_amount, r: t.r + a.in_approval + a.validated }), { n: 0, v: 0, r: 0 });

  return (
    <div className="meal-dashboard-app">
      <Head title="Painel MEAL" />
      <AdminHeader topbar={false} />
      <main className="meal-dashboard-main cd">
        <div className="cd-heading">
          <div>
            <h1>Bem-vindo, {firstName}!</h1>
            <p>{data.province ? `Província de ${data.province} · ` : ''}{cycle ? `${cycle.name}${cycle.description ? ` · ${cycle.description}` : ''}` : 'Ainda não há ciclos'}</p>
          </div>
          <div className="cd-heading-tools">
            {data.cycles.length > 0 && (
              <label className="cd-cycle-select"><Icon name="calendar" />
                <select value={cycle?.id ?? ''} onChange={(e) => chooseCycle(e.target.value)} aria-label="Ciclo do painel">
                  {data.cycles.map((c) => <option key={c.id} value={c.id}>{c.name} · {c.status === 'OPEN' ? 'em curso' : 'fechado'}</option>)}
                </select>
              </label>
            )}
            <NotificationBell />
            <UserMenu />
          </div>
        </div>

        {loading && <div className="meal-loading">A carregar o painel…</div>}
        {!loading && setup.some((s) => !s.done) && <SetupSteps steps={setup} />}
        {!loading && !cycle && setup.length === 0 && (
          <div className="info-box">Ainda não há nenhum ciclo. O gestor MEAL abre o ciclo depois de montar o programa.</div>
        )}

        {!loading && cycle && k && (
          <>
            {live.map((f) => (
              <div key={f.district_id} className="md-banner">
                <span className="md-dot" aria-hidden="true" />
                <strong>Feira de {f.district} a decorrer</strong>
                <span>dia {f.day} de {f.days} ({formatDate(f.fair_starts_on)} a {formatDate(f.fair_ends_on)}) · {number(f.bought)} de {number(f.households)} já compraram · faltam {number(f.missing)}</span>
                <a href={missingHref(cycle.id, f.district_id)}>Lista de quem falta →</a>
              </div>
            ))}

            <section className="cd-kpis">
              <div className="cd-kpi cd-green"><Icon name="users" /><div><strong>{number(k.households)}</strong><span>Agregados no ciclo</span>
                {k.target ? <Pill value={share(k.households, k.target)}>{`${pct(share(k.households, k.target))} da meta (${number(k.target)})`}</Pill> : <small>Sem meta definida</small>}</div></div>
              <div className="cd-kpi cd-green"><Icon name="check" /><div><strong>{number(k.active_cards)}</strong><span>Cartões entregues</span>
                <Pill value={share(k.active_cards, k.households)}>{`${pct(share(k.active_cards, k.households))} dos agregados`}</Pill></div></div>
              <div className="cd-kpi cd-green"><Icon name="people" /><div><strong>{number(k.households - k.not_bought)}</strong><span>Já compraram</span>
                <Pill value={share(k.households - k.not_bought, k.households)}>{`${pct(share(k.households - k.not_bought, k.households))} · faltam ${number(k.not_bought)}`}</Pill></div></div>
              <div className="cd-kpi cd-green"><Icon name="wallet" /><div><strong>{formatMT(k.used)}</strong><span>Valor usado</span>
                <Pill value={k.used_share}>{`${pct(k.used_share)} de ${formatMT(k.allocated)}`}</Pill></div></div>
            </section>

            <section className="cd-grid cd-grid-wide">
              {live[0] ? <LiveFairPanel fair={live[0]} cycleId={cycle.id} /> : (
                <div className="cd-panel">
                  <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="chart" /></span><h2>Ritmo de utilização</h2></div>
                  {data.usage && <UsageChart usage={data.usage} totalDays={cycle.total_days} />}
                  <div className="cd-legend">
                    <span><i className="cd-dot-green" /> {cycle.name}</span>
                    {data.usage?.previous && <span><i className="cd-dot-grey" /> {data.usage.previous.name} nos mesmos dias</span>}
                  </div>
                </div>
              )}
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="flag" /></span><h2>Metas por distrito</h2>
                  <a href="/admin/cycles" className="cd-link">Editar →</a></div>
                <div className="md-bars">
                  {districts.filter((d) => d.target).map((d) => {
                    const v = share(d.households, d.target ?? 0);
                    return <div key={d.district_id}><span>{d.district}</span><Bar value={v} tone={v < 0.9 ? 'amber' : 'green'} /><b>{pct(v)}</b></div>;
                  })}
                  {districts.every((d) => !d.target) && <p className="cd-note">Ainda não há metas: defina-as no ciclo, em Metas e feiras por distrito.</p>}
                </div>
                <p className="cd-note">Agregados no ciclo / meta do distrito</p>
              </div>
            </section>

            <section className="cd-grid cd-grid-3">
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="calendar" /></span><h2>Feiras do ciclo</h2></div>
                <div className="cd-table">
                  <table>
                    <thead><tr><th>Distrito</th><th>Datas</th><th>Estado</th><th>Compraram</th></tr></thead>
                    <tbody>
                      {districts.map((d) => (
                        <tr key={d.district_id}>
                          <td><strong>{d.district}</strong></td>
                          <td>{d.fair_starts_on ? `${formatDate(d.fair_starts_on).slice(0, 5)}–${formatDate(d.fair_ends_on).slice(0, 5)}` : '—'}</td>
                          <td><span className={`md-st md-st-${d.fair_status}`}>{FAIR_STATUS[d.fair_status]}</span></td>
                          <td>{d.fair_status === 'none' || d.fair_status === 'planned' || d.households === 0 ? '—' : <Pill value={share(d.bought, d.households)} />}</td>
                        </tr>
                      ))}
                      {districts.length === 0 && <tr><td colSpan={4} className="cd-note">Ainda não há feiras marcadas.</td></tr>}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-blue"><Icon name="people" /></span><h2>Por sexo do representante</h2></div>
                <div className="md-donut">
                  <Donut part={share(women?.households ?? 0, totalSex)} total={totalSex} label="agregados" />
                  <div className="md-legend">
                    {sex.map((s) => (
                      <div key={s.sex}><i className={s.sex === 'F' ? 'md-f' : 'md-m'} />{s.sex === 'F' ? 'Mulheres' : 'Homens'} <b>{number(s.households)} ({pct(share(s.households, totalSex))})</b>
                        <small>{pct(share(s.bought, s.households))} já compraram</small></div>
                    ))}
                  </div>
                </div>
                <p className="cd-note">{number(data.substitutes ?? 0)} cartões entregues a substituto do representante.</p>
              </div>
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-amber"><Icon name="alert" /></span><h2>Alertas e pontos de atenção</h2></div>
                <AlertList alerts={data.alerts} />
              </div>
            </section>

            <section className="cd-grid cd-grid-3">
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="layers" /></span><h2>Utilização por escalão</h2></div>
                <div className="md-bars">
                  {(data.by_tier ?? []).map((t) => {
                    const v = share(t.used, t.allocated);
                    return <div key={t.tier}><span>{t.tier}</span><Bar value={v} tone={v < 0.5 ? 'amber' : 'green'} /><b>{pct(v)}</b></div>;
                  })}
                </div>
                <p className="cd-note">Valor usado / atribuído ({(data.by_tier ?? []).map((t) => formatMT(t.amount)).join(' · ')})</p>
                <div className="md-map"><MozambiqueMap figures={data.by_province ?? []} withoutProvince={0} period="no ciclo" /></div>
              </div>
              <div className="cd-panel" id="sem-compras">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-blue"><Icon name="store" /></span><h2>Comerciantes</h2>
                  <Link href="/admin/settlements" className="cd-link">Reconciliações →</Link></div>
                <div className="cd-table">
                  <table>
                    <thead><tr><th>Comerciante</th><th>Compras</th><th>Valor</th><th>Reconciliado</th></tr></thead>
                    <tbody>
                      {dealers.map((a) => (
                        <tr key={a.code}><td><strong>{a.name}</strong></td><td>{number(a.transactions)}</td><td>{formatMT(a.voucher_amount)}</td>
                          <td><Pill value={share(a.in_approval + a.validated, a.voucher_amount)} /></td></tr>
                      ))}
                      {dealers.length > 0 && (
                        <tr className="md-total"><td>Total</td><td>{number(dealerTotal.n)}</td><td>{formatMT(dealerTotal.v)}</td><td><Pill value={share(dealerTotal.r, dealerTotal.v)} /></td></tr>
                      )}
                      {dealers.length === 0 && <tr><td colSpan={4} className="cd-note">Ainda não há compras neste ciclo.</td></tr>}
                    </tbody>
                  </table>
                </div>
                <h3 className="md-sub">Bairros com menos compras</h3>
                <div className="cd-table">
                  <table>
                    <tbody>
                      {(data.not_bought_by_community ?? []).slice(0, 5).map((c) => (
                        <tr key={`${c.community}-${c.district}`}><td><strong>{c.community}</strong><small>{c.district ?? ''}</small></td>
                          <td>{number(c.households - c.not_bought)} de {number(c.households)}</td><td><Pill value={share(c.households - c.not_bought, c.households)} /></td></tr>
                      ))}
                      {(data.not_bought_by_community ?? []).length === 0 && <tr><td className="cd-note">Todos os agregados do ciclo já compraram.</td></tr>}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="list" /></span><h2>Actividades recentes</h2></div>
                <ul className="md-timeline">
                  {data.activity.map((a, i) => <li key={i}><strong>{a.text}</strong><span>{formatDateTime(a.at)}{a.actor ? ` · ${a.actor}` : ''}</span></li>)}
                  {data.activity.length === 0 && <li className="cd-note">Ainda não há actividade.</li>}
                </ul>
              </div>
            </section>
          </>
        )}
      </main>
    </div>
  );
}
