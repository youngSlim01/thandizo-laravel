import { Head, Link, usePage } from '@inertiajs/react';
import { type Alert, AlertList, Icon, number } from '@/components/dashboard-ui';
import { FinanceLayout } from '@/components/finance-layout';
import { formatDate, formatMT } from '@/lib/format';
import type { SharedProps } from '@/types';

// Painel das Finanças: o estado actual, sem comparações com meses
// (FinanceDashboardService). O pagamento faz-se no sistema da organização;
// aqui o último passo é a validação.

type Dashboard = {
    deadline_days: number;
    kpis: {
        awaiting: number; awaiting_amount: number; overdue: number; overdue_amount: number; oldest_days: number;
        validated_amount: number; in_meal_amount: number; not_reconciled_amount: number;
    };
    alerts: Alert[];
    inbox: {
        id: string; settlement_code: string; agrodealer: string; agrodealer_code: string; period_start: string; period_end: string;
        transactions: number; amount: number; arrived_at: string | null; days_waiting: number; overdue: boolean;
    }[];
    by_agrodealer: { name: string | null; code: string | null; not_reconciled: number; in_meal: number; awaiting_finance: number; validated: number }[];
    by_cycle: { id: string; code: string; name: string; status: 'OPEN' | 'CLOSED'; allocated: number; used: number; expired: number | null; validated: number; pending: number }[];
};

export default function FinanceDashboard({ dashboard: d }: { dashboard: Dashboard }) {
    const user = usePage<SharedProps>().props.auth.user;
    const k = d.kpis;
    const firstName = (user?.full_name ?? '').split(' ')[0];

    return (
        <FinanceLayout>
            <Head title="Painel das Finanças" />
            <div className="cd">
                <div className="cd-heading">
                    <div>
                        <h1>Bem-vindo, {firstName}!</h1>
                        <p>Reconciliações e valores devidos aos comerciantes · prazo de validação: {d.deadline_days} dias</p>
                    </div>
                </div>

                <section className="cd-kpis">
                    <div className="cd-kpi cd-blue"><Icon name="inbox" /><div><strong>{number(k.awaiting)}</strong><span>À espera de validação</span><small>{formatMT(k.awaiting_amount)}{k.awaiting > 0 ? ` · a mais antiga há ${k.oldest_days} dia(s)` : ''}</small></div></div>
                    <div className={`cd-kpi ${k.overdue > 0 ? 'cd-amber' : 'cd-green'}`}><Icon name="clock" /><div><strong>{number(k.overdue)}</strong><span>Fora do prazo</span><small>{k.overdue > 0 ? `${formatMT(k.overdue_amount)} há mais de ${d.deadline_days} dias` : 'Nenhuma reconciliação atrasada'}</small></div></div>
                    <div className="cd-kpi cd-green"><Icon name="check" /><div><strong>{formatMT(k.validated_amount)}</strong><span>Validado</span><small>entregue ao pagamento da organização</small></div></div>
                    <div className="cd-kpi cd-green"><Icon name="wallet" /><div><strong>{formatMT(k.in_meal_amount + k.not_reconciled_amount)}</strong><span>A caminho</span><small>{formatMT(k.in_meal_amount)} no MEAL · {formatMT(k.not_reconciled_amount)} por reconciliar</small></div></div>
                </section>

                <section className="cd-panel">
                    <div className="cd-panel-head"><span className="cd-badge cd-badge-blue"><Icon name="inbox" /></span><h2>Caixa de entrada</h2>
                        <Link href="/finance/settlements" className="cd-link">Todas as reconciliações →</Link></div>
                    <div className="cd-table">
                        <table>
                            <thead><tr><th>Reconciliação</th><th>Período</th><th>Compras</th><th>A pagar</th><th>À espera</th><th /></tr></thead>
                            <tbody>
                                {d.inbox.map((s) => (
                                    <tr key={s.id}>
                                        <td><strong>{s.agrodealer}</strong><small>{s.settlement_code}</small></td>
                                        <td>{formatDate(s.period_start)} – {formatDate(s.period_end)}</td>
                                        <td>{number(s.transactions)}</td>
                                        <td>{formatMT(s.amount)}</td>
                                        <td className={s.overdue ? 'cd-late' : ''}>{s.days_waiting} dia(s){s.overdue ? ' · fora do prazo' : ''}</td>
                                        <td><Link href={`/finance/settlements?settlement=${s.id}`} className="cd-link">Validar →</Link></td>
                                    </tr>
                                ))}
                                {d.inbox.length === 0 && <tr><td colSpan={6} className="cd-note">Não há reconciliações à espera de validação.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="cd-grid cd-grid-wide">
                    <div className="cd-panel">
                        <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="store" /></span><h2>Por comerciante</h2></div>
                        <div className="cd-table">
                            <table>
                                <thead><tr><th>Comerciante</th><th>Por reconciliar</th><th>No MEAL</th><th>Nas Finanças</th><th>Validado</th></tr></thead>
                                <tbody>
                                    {d.by_agrodealer.map((a) => (
                                        <tr key={a.code ?? a.name}>
                                            <td><strong>{a.name}</strong><small>{a.code}</small></td>
                                            <td>{formatMT(a.not_reconciled)}</td>
                                            <td>{formatMT(a.in_meal)}</td>
                                            <td className={a.awaiting_finance > 0 ? 'cd-late' : ''}>{formatMT(a.awaiting_finance)}</td>
                                            <td>{formatMT(a.validated)}</td>
                                        </tr>
                                    ))}
                                    {d.by_agrodealer.length === 0 && <tr><td colSpan={5} className="cd-note">Ainda não há compras.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div className="cd-panel">
                        <div className="cd-panel-head"><span className="cd-badge cd-badge-amber"><Icon name="alert" /></span><h2>Alertas e pontos de atenção</h2></div>
                        <AlertList alerts={d.alerts} empty="Nada à espera das Finanças." />
                    </div>
                </section>

                <section className="cd-panel">
                    <div className="cd-panel-head"><span className="cd-badge cd-badge-green"><Icon name="layers" /></span><h2>Por ciclo</h2>
                        <Link href="/finance/reports" className="cd-link">Relatórios →</Link></div>
                    <div className="cd-table">
                        <table>
                            <thead><tr><th>Ciclo</th><th>Atribuído</th><th>Usado</th><th>Expirado</th><th>Validado</th><th>Por validar</th></tr></thead>
                            <tbody>
                                {d.by_cycle.map((c) => (
                                    <tr key={c.id}>
                                        <td><strong>{c.name}</strong><small>{c.code} · {c.status === 'OPEN' ? 'em curso' : 'fechado'}</small></td>
                                        <td>{formatMT(c.allocated)}</td>
                                        <td>{formatMT(c.used)}</td>
                                        <td>{c.expired === null ? '—' : formatMT(c.expired)}</td>
                                        <td>{formatMT(c.validated)}</td>
                                        <td className={c.pending > 0 ? 'cd-late' : ''}>{formatMT(c.pending)}</td>
                                    </tr>
                                ))}
                                {d.by_cycle.length === 0 && <tr><td colSpan={6} className="cd-note">Ainda não há ciclos.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </FinanceLayout>
    );
}
