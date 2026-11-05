
import Link from 'next/link';
import { OperatorGate } from '../components/operator-gate';
import { PosHeader } from '../components/pos-header';
import { useAuth } from '../auth-context';

function Profile() {
  const auth = useAuth();
  const operator = auth.operator;

  return (
    <main className="pos-shell">
      <PosHeader title="Perfil" back="/" />
      <section className="pos-content profile-content">
        <div className="profile-hero">
          <div className="profile-avatar">{operator?.fullName?.charAt(0).toUpperCase() ?? 'O'}</div>
          <div>
            <span className="eyebrow">Operador</span>
            <h1>{operator?.fullName ?? 'Operador'}</h1>
            <p>{operator?.role === 'AGRODEALER_MANAGER' ? 'Gestor do agrodealer' : 'Operador de POS'}</p>
          </div>
        </div>

        <div className="profile-card">
          <div className="profile-card-title">Local de trabalho</div>
          <div className="profile-detail"><span>Agrodealer</span><strong>{operator?.agrodealerName ?? '—'}</strong></div>
          <div className="profile-detail"><span>POS seleccionado</span><strong>{auth.pos?.code ?? 'Nenhum'}</strong></div>
          {auth.pos?.label && <div className="profile-detail"><span>Dispositivo</span><strong>{auth.pos.label}</strong></div>}
        </div>

        <div className="profile-card">
          <div className="profile-card-title">Estado da aplicação</div>
          <div className="profile-status"><span className="online-dot" /><strong>Sistema online</strong><small>Sessão activa e pronta para vendas.</small></div>
        </div>

        {auth.posList.length > 1 && (
          <div className="profile-card">
            <div className="profile-card-title">Terminal</div>
            <p>Está a trabalhar no POS <strong>{auth.pos?.code ?? '—'}</strong>.</p>
            <button className="secondary-button" onClick={() => auth.selectPos('')}>MUDAR DE POS</button>
          </div>
        )}

        <Link href="/sales" className="quick-link profile-link">Ver minhas vendas →</Link>
        <Link href="/account/password" className="quick-link profile-link">Alterar palavra-passe →</Link>
        <button className="quick-link profile-signout" onClick={() => auth.signOut()}>Sair da aplicação</button>
      </section>
    </main>
  );
}

export default function ProfilePage() {
  return <OperatorGate><Profile /></OperatorGate>;
}