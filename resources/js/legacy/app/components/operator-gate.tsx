
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { BACK_OFFICE_ROLES, SELLER_ROLES, useAuth } from '../auth-context';
import { errorMessage } from '../../lib/errors';

// Renders its children only for a logged-in seller with a POS selected.
// Without a session it sends the user to /login; without a POS it asks
// the operator to choose one.
export function OperatorGate({ children }: { children: React.ReactNode }) {
  const auth = useAuth();
  const router = useRouter();

  const role = auth.operator?.role ?? '';
  const isSeller = SELLER_ROLES.includes(role);
  const isBackOffice = BACK_OFFICE_ROLES.includes(role);

  useEffect(() => {
    if (!auth.loading && !auth.session && auth.profileError !== 'SUPABASE_NOT_CONFIGURED') {
      router.replace('/login');
    } else if (!auth.loading && auth.operator && !isSeller && isBackOffice) {
      // Administrators and MEAL do not sell; send them to the back office.
      router.replace(role === 'FINANCE_OFFICER' ? '/finance' : '/admin');
    }
  }, [auth.loading, auth.session, auth.profileError, auth.operator, isSeller, isBackOffice, router]);

  if (auth.loading || (!auth.session && auth.profileError !== 'SUPABASE_NOT_CONFIGURED')) {
    return <main className="pos-shell"><section className="pos-content"><p>A carregar…</p></section></main>;
  }

  if (auth.profileError) {
    return (
      <main className="pos-shell">
        <section className="pos-content">
          <h1>Acesso indisponível</h1>
          <div className="error-box">{errorMessage(auth.profileError)}</div>
          {auth.session && (
            <button className="secondary-button" onClick={() => auth.signOut()}>SAIR</button>
          )}
        </section>
      </main>
    );
  }

  if (auth.operator && !isSeller) {
    if (isBackOffice) return null;
    return (
      <main className="pos-shell">
        <section className="pos-content">
          <h1>Sem acesso ao POS</h1>
          <div className="error-box">{errorMessage('ROLE_NOT_ALLOWED')}</div>
          <button className="secondary-button" onClick={() => auth.signOut()}>SAIR</button>
        </section>
      </main>
    );
  }

  if (auth.operator && !auth.operator.agrodealerId) {
    return (
      <main className="pos-shell">
        <section className="pos-content">
          <h1>Acesso indisponível</h1>
          <div className="error-box">{errorMessage('AGRODEALER_NOT_ALLOWED')}</div>
          <button className="secondary-button" onClick={() => auth.signOut()}>SAIR</button>
        </section>
      </main>
    );
  }

  if (!auth.pos) {
    return (
      <main className="pos-shell">
        <section className="pos-content">
          <div className="eyebrow">{auth.operator?.agrodealerName}</div>
          <h1>Escolher POS</h1>
          {auth.posList.length === 0 ? (
            <div className="error-box">A sua loja não tem nenhum POS ativo. Contacte o MEAL.</div>
          ) : (
            <>
              <p>Indique em que terminal está a trabalhar.</p>
              <div className="quick-list">
                {auth.posList.map((pos) => (
                  <button key={pos.id} className="quick-link" onClick={() => auth.selectPos(pos.id)}>
                    {pos.code}{pos.label ? ` — ${pos.label}` : ''}
                  </button>
                ))}
              </div>
            </>
          )}
          <button className="secondary-button" onClick={() => auth.signOut()}>SAIR</button>
        </section>
      </main>
    );
  }

  return <>{children}</>;
}
