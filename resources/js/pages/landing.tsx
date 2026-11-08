import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ThandizoMark } from '@/components/thandizo-mark';
import { MOZ_MAP_HEIGHT, MOZ_MAP_WIDTH, MOZ_PROVINCES } from '@/lib/mozambique-map';
import type { SharedProps } from '@/types';
import '@fontsource-variable/montserrat';
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
    store: <><path d="M4 10v9h16v-9" /><path d="M3 10 5 5h14l2 5" /><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" /></>,
    chart: <><path d="M5 20v-6M11 20V9M17 20V4" /><rect x="3.5" y="14" width="3" height="6" rx=".5" /><rect x="9.5" y="9" width="3" height="11" rx=".5" /><rect x="15.5" y="4" width="3" height="16" rx=".5" /></>,
    wifi: <><path d="M2 9a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0" /><circle cx="12" cy="19" r=".8" /></>,
    phone: <><rect x="7" y="2.5" width="10" height="19" rx="2" /><path d="M11 18.5h2" /></>,
    shield: <><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6l-7-3Z" /><path d="m9 12 2 2 4-4" /></>,
    gear: <><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /><circle cx="12" cy="12" r="3" /></>,
    arrow: <path d="M5 12h14M13 6l6 6-6 6" />,
    login: <><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" /><path d="M3 12h11M10 8l4 4-4 4" /></>,
};

const solutions = [
    { icon: icons.people, title: 'Gestão de beneficiários', text: 'Registo, importação da lista MEAL e actualização dos agregados, com escalões de valor e histórico de cada alteração.' },
    { icon: icons.card, title: 'Gestão de vouchers', text: 'Cartões com QR por bairro, entrega em mão, saldo por escalão e recarga em cada ciclo, no mesmo cartão.' },
    { icon: icons.store, title: 'Gestão de comerciantes', text: 'Rede de comerciantes com catálogo, preços por distrito, descontos e vendas no POS pelo telemóvel.' },
    { icon: icons.chart, title: 'Monitoria e resultados', text: 'Painel por ciclo, relatórios, reconciliação com aprovação do MEAL e validação das Finanças.' },
];

const roles = [
    { title: 'Administrador do sistema', text: 'Utilizadores, perfis, configuração e saúde da plataforma.' },
    { title: 'Gestor e Oficial MEAL', text: 'Beneficiários, cartões, ciclos, comerciantes e painéis.' },
    { title: 'Oficial de Finanças', text: 'Validação das reconciliações e pagamentos aos comerciantes.' },
    { title: 'Comerciante (POS)', text: 'Vendas com o cartão, copagamento, recibo e anulação.' },
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
                                <path d="M4 74 L36 64 L68 55 L100 44 L132 36 L164 24 L196 14 L196 78 L4 78Z" fill="#e3f3e8" />
                                <path d="M4 74 L36 64 L68 55 L100 44 L132 36 L164 24 L196 14" fill="none" stroke="#1d8a4a" strokeWidth="2" />
                            </svg>
                        </div>
                        <div className="lp-map">
                            <svg viewBox={`0 0 ${MOZ_MAP_WIDTH} ${MOZ_MAP_HEIGHT}`}>
                                {MOZ_PROVINCES.map((p) => <path key={p.name} d={p.d} fill={shaded.has(p.name) ? '#3f9b62' : '#cfe7d6'} stroke="#fff" strokeWidth="2" />)}
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

const links = [['#plataforma', 'Plataforma'], ['#funcionalidades', 'Funcionalidades'], ['#perfis', 'Perfis'], ['#configuracao', 'Configuração'], ['#sobre', 'Sobre']];

export default function Landing() {
    const { brand } = usePage<SharedProps>().props;
    const client = [brand.client, brand.programme].filter(Boolean).join(' · ');

    return (
        <div className="lp">
            <Head title="Gestão de vouchers e assistência" />
            <header className="lp-header">
                <Brand />
                <nav aria-label="Secções">{links.map(([href, label]) => <a key={href} href={href}>{label}</a>)}</nav>
                <Link href="/login" className="lp-login"><Icon size={18}>{icons.login}</Icon>Entrar</Link>
            </header>

            <main>
                <section className="lp-hero" id="plataforma">
                    <div className="lp-hero-copy">
                        <h1>Gestão de vouchers para uma assistência eficiente, transparente e com impacto.</h1>
                        <p>
                            Plataforma integrada para registar beneficiários, emitir e gerir vouchers, acompanhar a sua utilização
                            na rede de comerciantes e monitorar os resultados de cada ciclo.
                        </p>
                        <div className="lp-actions">
                            <a className="lp-button" href="#funcionalidades">Conhecer a solução <Icon size={18}>{icons.arrow}</Icon></a>
                            <Link className="lp-button lp-button-outline" href="/login">Entrar no sistema</Link>
                        </div>
                    </div>
                    <LaptopPreview />
                </section>

                <section className="lp-section" id="funcionalidades">
                    <h2>Soluções do Thandizo</h2>
                    <p className="lp-lead">Módulos integrados que cobrem todo o ciclo da assistência, do registo ao pagamento ao comerciante.</p>
                    <div className="lp-cards">
                        {solutions.map((s) => (
                            <article key={s.title} className="lp-card">
                                <span className="lp-card-icon"><Icon>{s.icon}</Icon></span>
                                <div><h3>{s.title}</h3><p>{s.text}</p></div>
                            </article>
                        ))}
                    </div>
                </section>

                <section className="lp-field">
                    <div className="lp-field-copy">
                        <h2>Uma plataforma para operar no terreno</h2>
                        <p>Registo simples, dados fiáveis e informação no momento em que acontece, do bairro ao escritório.</p>
                    </div>
                    <div className="lp-field-items">
                        <div><Icon>{icons.wifi}</Icon><span>Acesso online<br />e em tempo real</span></div>
                        <div><Icon>{icons.phone}</Icon><span>Uso no terreno<br />pelo telemóvel</span></div>
                        <div><Icon>{icons.shield}</Icon><span>Dinheiro protegido<br />e auditado</span></div>
                    </div>
                </section>

                <section className="lp-section" id="perfis">
                    <h2>Um acesso para cada papel</h2>
                    <p className="lp-lead">Depois de entrar, cada pessoa vê apenas o que lhe compete, com as permissões do seu perfil.</p>
                    <div className="lp-roles">
                        {roles.map((r) => <article key={r.title}><h3>{r.title}</h3><p>{r.text}</p></article>)}
                    </div>
                </section>

                <section className="lp-benefits" id="configuracao">
                    <article>
                        <span className="lp-benefit-icon"><Icon>{icons.gear}</Icon></span>
                        <div>
                            <h2>Configurável e adaptável</h2>
                            <p>Ajuste a plataforma às regras do seu programa: ciclos, escalões de valor do voucher, catálogo e preços, descontos, utilizadores e circuitos de aprovação.</p>
                        </div>
                    </article>
                    <article id="sobre">
                        <span className="lp-benefit-icon"><Icon>{icons.chart}</Icon></span>
                        <div>
                            <h2>Decisões baseadas em dados</h2>
                            <p>Painéis e relatórios para acompanhar a utilização, quem ainda não comprou, o desempenho dos comerciantes e o que falta pagar, ciclo a ciclo.</p>
                        </div>
                    </article>
                </section>

                <section className="lp-cta">
                    <div>
                        <h2>Pronto para gerir o seu programa de vouchers?</h2>
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
