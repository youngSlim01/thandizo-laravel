import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminHeader, UserMenu } from '@/components/admin-layout';
import { type Alert, AlertPanel, Bar, Icon, number, pct, share } from '@/components/dashboard-ui';
import { MozambiqueMap, type ProvinceFigure } from '@/components/mozambique-map';
import { formatDateTime, formatMT } from '@/lib/format';
import type { SharedProps } from '@/types';

type UsagePoint = { day: number; share: number };
type Dashboard = {
  cycles: { id: string; code: string; name: string; status: 'OPEN' | 'CLOSED' }[];
  province: string | null;
  alerts: { critical: Alert[]; attention: Alert[] };
  activity: { action: string; text: string; at: string; actor: string | null }[];
  cycle: null | {
    id: string; code: string; name: string; description: string | null; status: 'OPEN' | 'CLOSED'; starts_on: string | null; ends_on: string | null;
    closed_at: string | null; day: number; total_days: number | null; days_left: number | null; time_share: number | null;
  };
  kpis?: {
    households: number; active_cards: number; allocated: number; used: number; used_share: number; unused: number;
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

// Painel MEAL do dia-a-dia, por ciclo (CycleDashboardService). Mudar de ciclo
// volta a pedir só o painel.
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
  const timeShare = cycle?.time_share ?? null;
  const ahead = k && timeShare !== null ? k.used_share >= timeShare - 0.05 : null;

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
            <section className="cd-kpis">
              <div className="cd-kpi cd-green"><Icon name="users" /><div><strong>{number(k.households)}</strong><span>Agregados no ciclo</span><small>{number(k.active_cards)} com cartão ativo</small></div></div>
              <div className="cd-kpi cd-green"><Icon name="wallet" /><div><strong>{formatMT(k.used)}</strong><span>Usado pelo voucher</span><small>{pct(k.used_share)} de {formatMT(k.allocated)}</small></div></div>
              <div className="cd-kpi cd-amber"><Icon name="clock" /><div><strong>{number(k.not_bought)}</strong><span>Ainda não compraram</span><small>{pct(share(k.not_bought, k.households))} · {formatMT(k.not_bought_value)} parados</small></div></div>
              <div className="cd-kpi cd-blue"><Icon name="store" /><div><strong>{formatMT(k.unpaid)}</strong><span>Por pagar aos comerciantes</span><small>ainda não validado pelas Finanças</small></div></div>
            </section>
            <section className="cd-grid cd-grid-wide">
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="chart" /></span><h2>Ritmo de utilização</h2></div>
                <div className="cd-pace">
                  {cycle.total_days !== null && timeShare !== null && (
                    <div className="cd-pace-row"><span>Tempo · dia {cycle.day} de {cycle.total_days}</span><Bar value={timeShare} tone="grey" /><strong>{pct(timeShare)}</strong></div>
                  )}
                  <div className="cd-pace-row"><span>Valor usado</span><Bar value={k.used_share} /><strong>{pct(k.used_share)}</strong></div>
                  <p className="cd-note">
                    {ahead === null ? `Dia ${cycle.day} do ciclo. Defina a data de fim do ciclo para comparar com o tempo.`
                      : ahead ? 'O dinheiro está a ser usado ao ritmo do tempo do ciclo, ou mais depressa.'
                        : 'O uso está atrás do tempo do ciclo: veja quem ainda não comprou.'}
                  </p>
                </div>
                {data.usage && <UsageChart usage={data.usage} totalDays={cycle.total_days} />}
                <div className="cd-legend">
                  <span><i className="cd-dot-green" /> {cycle.name}</span>
                  {data.usage?.previous && <span><i className="cd-dot-grey" /> {data.usage.previous.name} nos mesmos dias</span>}
                </div>
              </div>
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="map" /></span><h2>Cobertura geográfica</h2></div>
                <MozambiqueMap figures={data.by_province ?? []} withoutProvince={0} period="no ciclo" />
              </div>
            </section>

            <section className="cd-grid cd-grid-3">
              <AlertPanel title="Alertas" tone="red" icon="alert" alerts={data.alerts.critical} />
              <AlertPanel title="Atenção" tone="amber" icon="flag" alerts={data.alerts.attention} />
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-blue"><Icon name="people" /></span><h2>Por sexo do representante</h2></div>
                <div className="cd-demo">
                  {(data.by_sex ?? []).map((s) => (
                    <div key={s.sex} className={`cd-demo-card ${s.sex === 'F' ? 'cd-demo-f' : 'cd-demo-m'}`}>
                      <div><strong>{s.sex === 'F' ? 'Mulheres' : 'Homens'}</strong><span>{number(s.bought)} de {number(s.households)} compraram · gasto médio {formatMT(s.average_spent)}</span></div>
                      <b>{pct(share(s.bought, s.households))}</b>
                    </div>
                  ))}
                  <p className="cd-note">{number(data.substitutes ?? 0)} cartões entregues a substituto do representante.</p>
                </div>
              </div>
            </section>

            <section className="cd-panel">
              <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="layers" /></span><h2>Utilização por escalão</h2></div>
              <div className="cd-table">
                <table>
                  <thead><tr><th>Escalão</th><th>Agregados</th><th>Compraram</th><th>Atribuído</th><th>Usado</th><th>Utilização</th>{timeShare !== null && <th>Estado</th>}</tr></thead>
                  <tbody>
                    {(data.by_tier ?? []).map((t) => {
                      const used = share(t.used, t.allocated);
                      const onPace = timeShare === null || used >= timeShare - 0.05;
                      return (
                        <tr key={t.tier}>
                          <td><strong>{t.tier}</strong><small>{formatMT(t.amount)}{t.generated_codes > 0 ? ` · ${t.generated_codes} sem AF` : ''}</small></td>
                          <td>{number(t.households)}</td>
                          <td>{number(t.bought)} ({pct(share(t.bought, t.households))})</td>
                          <td>{formatMT(t.allocated)}</td>
                          <td>{formatMT(t.used)}</td>
                          <td><div className="cd-bar-cell"><Bar value={used} tone={onPace ? 'green' : 'amber'} /><span>{pct(used)}</span></div></td>
                          {timeShare !== null && <td><span className={onPace ? 'cd-status-ok' : 'cd-status-late'}>{onPace ? 'NO RITMO' : 'ATRASADO'}</span></td>}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </section>

            <section className="cd-grid cd-grid-wide">
              <div className="cd-panel" id="sem-compras">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-amber"><Icon name="clock" /></span><h2>Quem ainda não comprou, por bairro</h2>
                  <Link href="/admin/households" className="cd-link">Listas para mobilização →</Link></div>
                <div className="cd-table">
                  <table>
                    <thead><tr><th>Bairro</th><th>Sem compras</th><th>% do bairro</th><th>Saldo por usar</th></tr></thead>
                    <tbody>
                      {(data.not_bought_by_community ?? []).map((c) => {
                        const s = share(c.not_bought, c.households);
                        return (
                          <tr key={`${c.community}-${c.district}`}>
                            <td><strong>{c.community}</strong><small>{c.district ?? ''}</small></td>
                            <td>{number(c.not_bought)} de {number(c.households)}</td>
                            <td className={s >= 0.3 ? 'cd-late' : ''}>{pct(s)}</td>
                            <td>{formatMT(c.unused)}</td>
                          </tr>
                        );
                      })}
                      {(data.not_bought_by_community ?? []).length === 0 && <tr><td colSpan={4} className="cd-note">Todos os agregados do ciclo já compraram.</td></tr>}
                    </tbody>
                  </table>
                </div>
              </div>
              <div className="cd-panel">
                <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="list" /></span><h2>Últimas actividades</h2></div>
                <ul className="cd-activity">
                  {data.activity.map((a, i) => (
                    <li key={i}><strong>{a.text}</strong><span>{formatDateTime(a.at)}{a.actor ? ` · ${a.actor}` : ''}</span></li>
                  ))}
                  {data.activity.length === 0 && <li className="cd-note">Ainda não há actividade.</li>}
                </ul>
              </div>
            </section>

            <section className="cd-panel">
              <div className="cd-panel-head"><span className="cd-badge cd-badge-blue"><Icon name="store" /></span><h2>Comerciantes no ciclo</h2>
                <Link href="/admin/settlements" className="cd-link">Reconciliações →</Link></div>
              <div className="cd-table">
                <table>
                  <thead><tr><th>Comerciante</th><th>Compras</th><th>Valor do voucher</th><th>Por reconciliar</th><th>Em aprovação</th><th>Validado</th></tr></thead>
                  <tbody>
                    {(data.by_agrodealer ?? []).map((a) => (
                      <tr key={a.code}>
                        <td><strong>{a.name}</strong><small>{a.code}</small></td>
                        <td>{number(a.transactions)}</td>
                        <td>{formatMT(a.voucher_amount)}</td>
                        <td className={a.to_reconcile > 0 ? 'cd-late' : ''}>{formatMT(a.to_reconcile)}</td>
                        <td>{formatMT(a.in_approval)}</td>
                        <td>{formatMT(a.validated)}</td>
                      </tr>
                    ))}
                    {(data.by_agrodealer ?? []).length === 0 && <tr><td colSpan={6} className="cd-note">Ainda não há compras neste ciclo.</td></tr>}
                  </tbody>
                </table>
              </div>
            </section>
          </>
        )}
      </main>
    </div>
  );
}
