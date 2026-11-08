import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';
import { MOZ_MAP_HEIGHT, MOZ_MAP_WIDTH, MOZ_PROVINCES } from '@/lib/mozambique-map';
import type { SharedProps } from '@/types';
import '@/../css/landing.css';

// Página pública do Thandizo: para quem quer saber o que a solução faz.
// Quem já tem sessão nunca a vê (WelcomeController leva-o à sua área).

function Icon({ children, size = 40 }: { children: ReactNode; size?: number }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.4} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {children}
        </svg>
    );
}

const icons = {
    people: <><circle cx="7" cy="8" r="2.6" /><circle cx="17" cy="8" r="2.6" /><circle cx="12" cy="6.5" r="2.6" /><path d="M2.5 18a4.5 4.5 0 0 1 9 0M12.5 18a4.5 4.5 0 0 1 9 0M7.5 16a4.5 4.5 0 0 1 9 0" /></>,
    card: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></>,
    chart: <><path d="M5 20v-6M11 20V9M17 20V4" /><rect x="3.5" y="14" width="3" height="6" rx=".5" /><rect x="9.5" y="9" width="3" height="11" rx=".5" /><rect x="15.5" y="4" width="3" height="16" rx=".5" /></>,
    gear: <><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /><circle cx="12" cy="12" r="3" /></>,
    calendar: <><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M7 2v4M17 2v4M3 9h18" /></>,
    swap: <><path d="M4 8h14M14 4l4 4-4 4" /><path d="M20 16H6M10 12l-4 4 4 4" /></>,
    check: <><rect x="4" y="3" width="16" height="18" rx="2" /><path d="m8 12 3 3 5-6" /></>,
    lock: <><rect x="5" y="10.5" width="14" height="10" rx="2" /><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" /></>,
    layers: <><path d="m12 3 9 5-9 5-9-5 9-5Z" /><path d="m3 13 9 5 9-5" /></>,
    arrow: <path d="M5 12h14M13 6l6 6-6 6" />,
    login: <><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" /><path d="M3 12h11M10 8l4 4-4 4" /></>,
};

const features = [
    { icon: icons.people, title: 'Beneficiários', text: 'Registo, importação de listas e actualização dos agregados, com histórico de cada alteração.' },
    { icon: icons.card, title: 'Vouchers', text: 'Cartões com QR, entrega em mão, valores por escalão e recarga em cada ciclo.' },
    { icon: icons.layers, title: 'Programas', text: 'Ciclos e janelas de assistência, com âmbito, datas e regras de cada programa.' },
    { icon: icons.chart, title: 'Monitoria', text: 'Painéis e relatórios do ciclo: utilização, quem ainda não comprou, por sexo e por zona.' },
    { icon: icons.swap, title: 'Transações', text: 'Vendas no POS pelo telemóvel, com copagamento, recibo e anulação controlada.' },
    { icon: icons.check, title: 'Reconciliação', text: 'Circuito do MEAL às Finanças, com aprovação e validação de cada pagamento ao comerciante.' },
    { icon: icons.gear, title: 'Administração', text: 'Utilizadores, perfis, escalões, catálogo, preços e marca de cada organização.' },
    { icon: icons.lock, title: 'Segurança', text: 'Acesso por perfil, histórico financeiro que não se altera e auditoria de cada acção.' },
];

const steps = [
    { title: 'Planear', text: 'Registar beneficiários, definir escalões, comerciantes, preços e o ciclo de assistência.' },
    { title: 'Implementar', text: 'Distribuir cartões, carregar os valores e vender nos comerciantes da rede.' },
    { title: 'Acompanhar', text: 'Seguir a utilização dia a dia, quem ainda não comprou e o que precisa de acção.' },
    { title: 'Reportar', text: 'Reconciliar, validar pagamentos e gerar os relatórios do ciclo para a organização e o doador.' },
];

const roles = [
    { title: 'Administrador do sistema', text: 'Utilizadores, perfis, configuração e saúde da plataforma.' },
    { title: 'Gestor MEAL', text: 'Ciclos, escalões, aprovações e configuração do programa.' },
    { title: 'Oficial MEAL', text: 'Beneficiários, cartões, monitoria e reconciliações na sua província.' },
    { title: 'Finanças', text: 'Validação das reconciliações e relatórios financeiros.' },
    { title: 'Comerciante', text: 'Vendas com o cartão no POS, recibo e anulação.' },
];

const impact = [
    { title: 'Inclusão', text: 'Valores ajustados à vulnerabilidade de cada agregado e acompanhamento de quem ainda não foi alcançado.' },
    { title: 'Eficiência', text: 'Menos papel e menos tarefas manuais, do registo ao pagamento ao comerciante.' },
    { title: 'Transparência', text: 'Cada movimento de dinheiro registado, aprovado e auditável.' },
    { title: 'Impacto', text: 'Decisões apoiadas em dados actualizados, ciclo a ciclo.' },
];

// Ilustração do painel (não são dados reais).
function LaptopPreview() {
    const shaded = new Set(['Tete', 'Manica', 'Sofala', 'Zambezia', 'Gaza']);
    return (
        <div className="lp-laptop" role="img" aria-label="Ilustração do painel do Thandizo">
            <div className="lp-screen">
                <div className="lp-sidebar"><span><ThandizoMark /></span><i /><i /><i /><i /><i /></div>
                <div className="lp-app">
                    <div className="lp-app-top"><strong>Painel do ciclo</strong><span>AA - Janela 1</span></div>
                    <div className="lp-kpis">
                        <div><b>1 240</b><small>Agregados</small></div>
                        <div><b>1 198</b><small>Cartões ativos</small></div>
                        <div><b>854</b><small>Compraram</small></div>
                        <div><b>55%</b><small>Valor usado</small></div>
                    </div>
                    <div className="lp-panels">
                        <div className="lp-chart">
                            <small>Ritmo de utilização</small>
                            <svg viewBox="0 0 200 80" preserveAspectRatio="none">
                                <path d="M4 74 L36 64 L68 55 L100 44 L132 36 L164 24 L196 14 L196 78 L4 78Z" fill="#ebf6ee" />
                                <path d="M4 74 L36 64 L68 55 L100 44 L132 36 L164 24 L196 14" fill="none" stroke="#007716" strokeWidth="2" />
                            </svg>
                        </div>
                        <div className="lp-map">
                            <svg viewBox={`0 0 ${MOZ_MAP_WIDTH} ${MOZ_MAP_HEIGHT}`}>
                                {MOZ_PROVINCES.map((p) => <path key={p.name} d={p.d} fill={shaded.has(p.name) ? '#3a9d55' : '#c6e4cf'} stroke="#fff" strokeWidth="2" />)}
                            </svg>
                            <ul><li /><li /><li /><li /></ul>
                        </div>
                    </div>
                </div>
            </div>
            <div className="lp-base" />
        </div>
    );
}

function Brand() {
    return (
        <Link href="/" className="lp-brand">
            <span className="lp-mark"><ThandizoMark /></span>
            <strong>Thandizo</strong>
            <span className="lp-tagline">Empowering programmes.<br />Improving lives.</span>
        </Link>
    );
}

const links = [['#sobre', 'Sobre'], ['#funcionalidades', 'Funcionalidades'], ['#como-funciona', 'Como funciona'], ['#perfis', 'Perfis'], ['#impacto', 'Impacto']];

export default function Landing() {
    const { brand } = usePage<SharedProps>().props;
    const client = [brand.client, brand.programme].filter(Boolean).join(' · ');

    return (
        <div className="lp">
            <Head title="Gestão de programas, assistência e impacto" />
            <header className="lp-header">
                <Brand />
                <nav aria-label="Secções">{links.map(([href, label]) => <a key={href} href={href}>{label}</a>)}</nav>
                <Link href="/login" className="lp-login"><Icon size={18}>{icons.login}</Icon>Entrar</Link>
            </header>

            <main>
                <section className="lp-hero" id="inicio">
                    <div className="lp-hero-copy">
                        <h1>Cada apoio chega a quem precisa. E fica provado.</h1>
                        <p>Beneficiários, vouchers e pagamentos aos comerciantes num só sistema, com cada metical registado, aprovado e auditável.</p>
                        <div className="lp-actions">
                            <a className="lp-button" href="#sobre">Conhecer a solução <Icon size={18}>{icons.arrow}</Icon></a>
                            <Link className="lp-button lp-button-outline" href="/login">Entrar no sistema</Link>
                        </div>
                    </div>
                    <LaptopPreview />
                </section>

                <section className="lp-section" id="sobre">
                    <h2>Sobre o Thandizo</h2>
                    <p className="lp-lead lp-lead-wide">
                        O Thandizo apoia a gestão de programas e a assistência a beneficiários, integrando processos, dados e actores
                        num único sistema seguro, fiável e orientado a resultados.
                    </p>
                    <div className="lp-about">
                        <article><span>Propósito</span><p>Aumentar a eficiência, a inclusão e o impacto dos programas.</p></article>
                        <article><span>Solução</span><p>Centralizar a gestão, o seguimento da assistência e a monitoria.</p></article>
                        <article><span>Para quem</span><p>Organizações, equipas de programa, MEAL, finanças, comerciantes e parceiros.</p></article>
                    </div>
                </section>

                <section className="lp-section" id="funcionalidades">
                    <h2>Funcionalidades</h2>
                    <p className="lp-lead">Módulos integrados que cobrem todo o ciclo da assistência.</p>
                    <div className="lp-features">
                        {features.map((f) => (
                            <article key={f.title}>
                                <span className="lp-card-icon"><Icon size={32}>{f.icon}</Icon></span>
                                <h3>{f.title}</h3>
                                <p>{f.text}</p>
                            </article>
                        ))}
                    </div>
                </section>

                <section className="lp-section" id="como-funciona">
                    <h2>Como funciona</h2>
                    <p className="lp-lead">Quatro passos, num só sistema.</p>
                    <ol className="lp-steps">
                        {steps.map((st, i) => (
                            <li key={st.title}><b>{i + 1}</b><h3>{st.title}</h3><p>{st.text}</p></li>
                        ))}
                    </ol>
                </section>

                <section className="lp-section" id="perfis">
                    <h2>Perfis</h2>
                    <p className="lp-lead">Depois de entrar, cada pessoa vê apenas o que lhe compete, com as permissões do seu perfil.</p>
                    <div className="lp-roles">
                        {roles.map((r) => <article key={r.title}><h3>{r.title}</h3><p>{r.text}</p></article>)}
                    </div>
                </section>

                <section className="lp-section" id="impacto">
                    <h2>Impacto</h2>
                    <p className="lp-lead">Mais inclusão, eficiência, transparência e impacto.</p>
                    <div className="lp-impact">
                        {impact.map((it) => <article key={it.title}><h3>{it.title}</h3><p>{it.text}</p></article>)}
                    </div>
                </section>

                <section className="lp-cta">
                    <div>
                        <h2>Pronto para gerir o seu programa?</h2>
                        <p>Junte a sua equipa e gira a assistência com mais controlo, eficiência e impacto.</p>
                    </div>
                    <Link className="lp-button" href="/login">Entrar na plataforma <Icon size={18}>{icons.arrow}</Icon></Link>
                </section>
            </main>

            <footer className="lp-footer">
                <Brand />
                <nav aria-label="Rodapé">{links.map(([href, label]) => <a key={href} href={href}>{label}</a>)}</nav>
                <small>© {new Date().getFullYear()} Thandizo{client ? ` · Em uso: ${client}` : ''}</small>
            </footer>
        </div>
    );
}
