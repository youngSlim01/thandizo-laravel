import { Head, Link } from '@inertiajs/react';
import { ThandizoMark } from '@/components/thandizo-mark';
import '@/../css/landing.css';

const solutions = [
  { icon: '♧', title: 'Agricultura e meios de vida', text: 'Gestão de vouchers agrícolas, insumos, apoio a produtores, beneficiários e distribuição de recursos.' },
  { icon: '♙', title: 'Assistência humanitária e social', text: 'Gestão de assistência em cash, vouchers, benefícios, distribuição e acompanhamento de beneficiários.' },
  { icon: '▱', title: 'Programas de desenvolvimento', text: 'Gestão de programas, projectos, actividades, participantes, equipas e processos de implementação.' },
  { icon: '⌁', title: 'Monitoria, avaliação e aprendizagem (MEAL)', text: 'Acompanhamento de indicadores, resultados, progresso, dados, relatórios e visualização de informação.' },
];

function DashboardPreview() {
  return <div className="landing-preview" aria-label="Ilustração do dashboard THANDIZO">
    <div className="preview-window">
      <div className="preview-sidebar"><span className="preview-mark"><ThandizoMark /></span><i>♙</i><i>♧</i><i>▱</i><i>⌁</i><i>▤</i></div>
      <div className="preview-content">
        <div className="preview-top"><strong>Thandizo</strong><span>Todos os programas⌄</span></div>
        <div className="preview-kpis"><div><b>12.480</b><small>Beneficiários</small></div><div><b>8</b><small>Programas</small></div><div><b>8.920</b><small>Assistências</small></div><div><b>73%</b><small>Realização</small></div></div>
        <div className="preview-panels"><div className="preview-chart"><small>Evolução dos resultados</small><svg viewBox="0 0 280 110" role="img" aria-label="Gráfico ilustrativo"><path d="M8 92 L55 75 L100 63 L145 43 L190 30 L240 12" fill="none" stroke="#16834b" strokeWidth="2"/><path d="M8 92 L55 75 L100 63 L145 43 L190 30 L240 12 L240 105 L8 105Z" fill="#dff2e5"/>{[8,55,100,145,190,240].map((x,i)=><circle key={i} cx={x} cy={[92,75,63,43,30,12][i]} r="3" fill="#16834b"/>)}</svg></div><div className="preview-map"><span>Distribuição geográfica</span><div className="map-shape">Moçambique</div><small>Manica · Tete · Gaza</small></div></div>
      </div>
    </div><div className="preview-base"/></div>;
}

export default function Landing() {
  return <div className="landing-page">
    <Head title="THANDIZO | Gestão de programas e assistência"/>
    <header className="landing-header">
      <Link href="/" className="landing-brand"><span className="landing-mark"><ThandizoMark/></span><strong>Thandizo</strong><span className="landing-tagline">Empowering programmes.<br/>Improving lives.</span></Link>
      <nav><a href="#plataforma">Plataforma⌄</a><a href="#solucoes">Funcionalidades</a><a href="#solucoes">Soluções⌄</a><a href="#recursos">Recursos⌄</a><a href="#sobre">Sobre</a></nav>
      <Link href="/login" className="landing-login">↗ Entrar</Link>
    </header>
    <main>
      <section className="landing-hero" id="plataforma"><div className="hero-copy"><h1>Gestão de programas,<br/>assistência e impacto<br/>para o sector social.</h1><p>Uma plataforma integrada para gerir beneficiários, vouchers, actividades, resultados e aprendizagem com simplicidade, transparência e controlo.</p><div className="hero-actions"><a className="button-primary" href="#solucoes">Conhecer a plataforma <span>→</span></a><Link className="button-outline" href="/login">Entrar no sistema</Link></div></div><DashboardPreview/></section>
      <section className="landing-solutions" id="solucoes"><div className="section-intro"><h2>Soluções do THANDIZO</h2><p>Aplicações integradas que respondem às necessidades de programas humanitários e de desenvolvimento.</p></div><div className="solution-grid">{solutions.map(s=><article className="solution-card" key={s.title}><span className="solution-icon">{s.icon}</span><div><h3>{s.title}</h3><p>{s.text}</p></div></article>)}</div></section>
      <section className="landing-field" id="recursos"><div className="field-copy"><h2>Uma plataforma para operações no terreno</h2><p>Suporta a implementação de programas em contextos reais, ligando equipas, dados e processos, mesmo em áreas com conectividade limitada.</p></div><div className="field-features"><article><span>⌖</span><p>Operação em<br/>múltiplas localidades</p></article><article><span>♙</span><p>Gestão de equipas<br/>e actividades</p></article><article><span>▯</span><p>Registo e sincronização<br/>de dados no terreno</p></article></div></section>
      <section className="landing-benefits" id="sobre"><article><span className="benefit-icon">☷</span><div><h2>Configurável à sua realidade</h2><p>Configure a plataforma de acordo com as necessidades do seu programa: formulários, fluxos, indicadores, perfis e relatórios.</p></div></article><article><span className="benefit-icon">▥</span><div><h2>Dados oportunos e de qualidade</h2><p>Reduza tarefas manuais, tenha informação actualizada e tome decisões com base em dados fiáveis e de qualidade.</p></div></article></section>
      <section className="landing-cta"><div><h2>Pronto para transformar a gestão dos seus programas?</h2><p>Use o THANDIZO para alcançar mais pessoas, com maior eficiência, transparência e impacto.</p></div><Link href="/login" className="button-primary">Entrar no THANDIZO <span>→</span></Link></section>
    </main>
    <footer className="landing-footer"><Link href="/" className="landing-brand"><span className="landing-mark"><ThandizoMark/></span><strong>Thandizo</strong><span className="landing-tagline">Empowering programmes.<br/>Improving lives.</span></Link><div className="footer-links"><a href="#plataforma">Plataforma</a><a href="#solucoes">Funcionalidades</a><a href="#solucoes">Soluções</a><a href="#recursos">Recursos</a><a href="#sobre">Sobre</a></div><small>© {new Date().getFullYear()} THANDIZO</small></footer>
  </div>;
}
