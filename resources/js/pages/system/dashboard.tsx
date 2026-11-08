import { Head, Link, usePage } from '@inertiajs/react';
import { AdminHeader, UserMenu } from '@/components/admin-layout';
import type { SharedProps } from '@/types';
import '@/../css/system-dashboard.css';

type Activity = { type: string; title: string; detail: string; date: string | null };
type DashboardData = {
  period: string; users_active: number; users_pending: number; cycles_total: number;
  transactions_total: number; transactions_value: number; agrodealers_total: number;
  transactions_rejected_24h: number; transactions_pending: number;
  roles: { label: string; count: number }[];
  recent_activity: Activity[];
};

const number = (value: number) => Number(value ?? 0).toLocaleString('pt-MZ');
const money = (value: number) => Number(value ?? 0).toLocaleString('pt-MZ', { maximumFractionDigits: 0 });

function Metric({ icon, value, label, tone }: { icon: string; value: string; label: string; tone: string }) {
  return <article className={`sys-metric ${tone}`}><span className="sys-metric-icon">{icon}</span><div><strong>{value}</strong><span>{label}</span></div></article>;
}
function Panel({ title, icon, children, className = '' }: { title: string; icon: string; children: React.ReactNode; className?: string }) {
  return <section className={`sys-panel ${className}`}><h2><span>{icon}</span>{title}</h2>{children}</section>;
}
function dateLabel(value: string | null) {
  if (!value) return 'Sem data';
  return new Date(value).toLocaleString('pt-MZ', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export default function SystemDashboard({ dashboard: data }: { dashboard: DashboardData }) {
  const user = usePage<SharedProps>().props.auth.user;
  return <div className="system-dashboard">
    <Head title="Dashboard System Admin · THANDIZO" />
    <AdminHeader topbar={false}/>
    <div className="sys-topbar"><button className="sys-menu" aria-label="Menu">☰</button><div className="sys-search">⌕ <span>Pesquisar no sistema (utilizador, programa, transacção…)</span></div><div className="sys-topbar-spacer"/><span className="sys-date">▦ {data.period}</span><button className="sys-bell" aria-label="Notificações">♧<i>3</i></button><UserMenu className="sys-user-menu"/></div>
    <main className="sys-main">
      <header className="sys-heading"><div><h1>Bom dia, {user?.full_name ?? 'Administrador'}!</h1><div className="sys-heading-sub"><span>Painel de administração do THANDIZO</span><b/> <em>System Admin</em></div></div><p>Administração, segurança e gestão global da plataforma.</p></header>
      <section className="sys-metrics">
        <Metric icon="♧" value={number(data.users_active)} label="Utilizadores activos" tone="green"/>
        <Metric icon="+" value={number(data.users_pending)} label="Utilizadores pendentes" tone="blue"/>
        <Metric icon="▱" value={number(data.cycles_total)} label="Ciclos registados" tone="mint"/>
        <Metric icon="⇄" value={number(data.transactions_total)} label="Transacções no sistema" tone="purple"/>
        <Metric icon="◌" value={money(data.transactions_value)+' MT'} label="Valor transaccionado" tone="gold"/>
      </section>
      <section className="sys-alert-grid">
        <Panel title="Alertas críticos" icon="!" className="critical"><div className="sys-alert-row"><span>Transacções rejeitadas (últimas 24h)</span><strong>{number(data.transactions_rejected_24h)}</strong></div><div className="sys-alert-row"><span>Falhas de sincronização</span><strong className="not-monitored">N/D</strong></div><div className="sys-alert-row"><span>Serviços indisponíveis</span><strong className="not-monitored">N/D</strong></div><div className="sys-alert-row"><span>Backups falhados</span><strong className="not-monitored">N/D</strong></div></Panel>
        <Panel title="Requer atenção" icon="!" className="attention"><div className="sys-alert-row"><span>Utilizadores por activar</span><strong>{number(data.users_pending)}</strong></div><div className="sys-alert-row"><span>Transacções pendentes</span><strong>{number(data.transactions_pending)}</strong></div><div className="sys-alert-row"><span>Comerciantes registados</span><strong>{number(data.agrodealers_total)}</strong></div><div className="sys-alert-row"><span>Configurações por rever</span><strong className="not-monitored">N/D</strong></div></Panel>
        <Panel title="Saúde do Sistema" icon="⌁" className="health"><div className="sys-health-row"><i className="sys-health-unknown"/>Aplicação (Web)<b>Não monitorizado</b></div><div className="sys-health-row"><i className="sys-health-unknown"/>Base de Dados<b>Não monitorizado</b></div><div className="sys-health-row"><i className="sys-health-unknown"/>API / Integrações<b>Não monitorizado</b></div><div className="sys-health-row"><i className="sys-health-unknown"/>Sincronização de Dados<b>Não monitorizado</b></div><div className="sys-health-row">Último backup<b>Não disponível</b></div></Panel>
      </section>
      <section className="sys-analysis-grid">
        <Panel title="Utilização da plataforma" icon="▥" className="usage"><div className="sys-chart-empty"><div className="sys-chart-y"><span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0</span></div><div className="sys-chart-area"><div className="sys-chart-lines"/><div className="sys-chart-placeholder"><span>Histórico de utilização ainda não disponível</span></div><div className="sys-chart-x"><span>Login</span><span>Transacções</span><span>Utilizadores</span></div></div></div></Panel>
        <Panel title="Programas e ciclos no sistema" icon="▱" className="programmes"><table className="sys-table"><thead><tr><th>Indicador</th><th>Valor</th></tr></thead><tbody><tr><td>Ciclos registados</td><td>{number(data.cycles_total)}</td></tr><tr><td>Transacções registadas</td><td>{number(data.transactions_total)}</td></tr><tr><td>Comerciantes</td><td>{number(data.agrodealers_total)}</td></tr></tbody><tfoot><tr><td>Valor confirmado (MT)</td><td>{money(data.transactions_value)}</td></tr></tfoot></table><Link href="/admin/cycles" className="sys-panel-link">Gerir ciclos →</Link></Panel>
      </section>
      <section className="sys-bottom-grid"><Panel title="Actividade recente (Sistema)" icon="≡" className="recent"><Link href="/admin/users" className="sys-see-all">Ver todas →</Link><div className="sys-activity-list">{data.recent_activity.length ? data.recent_activity.map((item,i)=><div className="sys-activity" key={i}><span className={item.type==='user'?'activity-user':'activity-transaction'}>{item.type==='user'?'♙':'⇄'}</span><div><strong>{item.title}</strong><small>{dateLabel(item.date)} · {item.detail}</small></div><em>{item.type==='user'?'Utilizador':'Sistema'}</em></div>):<p className="sys-empty">Ainda não existem actividades registadas.</p>}</div></Panel><aside className="sys-banner"><div className="sys-banner-copy"><strong>Thandizo</strong><p>Assistência que chega<br/>a quem mais precisa.</p><a href="/website">Ver o website →</a></div><img className="sys-banner-photo" src="/thandizo-login-bg.jpg" alt="Mulheres de uma comunidade apoiada pelo programa" /></aside></section>
      <footer className="sys-footnote">Indicadores calculados com base nos registos disponíveis. A monitoria técnica será integrada quando existirem serviços próprios.</footer>
    </main>
  </div>;
}
