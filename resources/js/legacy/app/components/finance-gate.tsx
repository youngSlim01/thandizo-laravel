
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '../auth-context';
import { errorMessage } from '../../lib/errors';

export const FINANCE_ROLES = ['FINANCE_OFFICER', 'SYSTEM_ADMIN'];

export function FinanceGate({ children }: { children: React.ReactNode }) {
  const auth = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!auth.loading && !auth.session && auth.profileError !== 'SUPABASE_NOT_CONFIGURED') {
      router.replace('/login');
    }
  }, [auth.loading, auth.session, auth.profileError, router]);

  if (auth.loading || (!auth.session && auth.profileError !== 'SUPABASE_NOT_CONFIGURED')) {
    return <main className="admin-shell"><p>A carregar…</p></main>;
  }

  const allowed = auth.operator && FINANCE_ROLES.includes(auth.operator.role) && !auth.profileError;
  if (!allowed) {
    return (
      <main className="admin-shell">
        <h1>Sem acesso</h1>
        <div className="error-box">{errorMessage('FINANCE_NOT_ALLOWED')}</div>
        <button className="secondary-button" style={{ maxWidth: 240 }} onClick={() => auth.signOut()}>SAIR</button>
      </main>
    );
  }

  return <>{children}</>;
}
