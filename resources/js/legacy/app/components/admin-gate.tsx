
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { USER_ADMIN_ROLES, useAuth } from '../auth-context';
import { errorMessage } from '../../lib/errors';

// Renders its children only for an active user with one of the given roles
// (by default SYSTEM_ADMIN or MEAL_MANAGER). The database checks the role
// again in every admin function.
export function AdminGate({ children, roles = USER_ADMIN_ROLES, deniedCode = 'USER_ADMIN_NOT_ALLOWED' }: {
  children: React.ReactNode;
  roles?: string[];
  deniedCode?: string;
}) {
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

  const allowed = auth.operator && roles.includes(auth.operator.role) && !auth.profileError;
  if (!allowed) {
    return (
      <main className="admin-shell">
        <h1>Sem acesso</h1>
        <div className="error-box">{errorMessage(auth.profileError ?? deniedCode)}</div>
        <button className="secondary-button" style={{ maxWidth: 240 }} onClick={() => auth.signOut()}>SAIR</button>
      </main>
    );
  }

  return <>{children}</>;
}
