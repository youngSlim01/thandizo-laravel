
import { useCallback, useEffect, useState } from 'react';
import { AdminGate } from '../../components/admin-gate';
import { AdminHeader } from '../../components/admin-header';
import { HOUSEHOLD_ROLES, USER_ADMIN_ROLES, useAuth } from '../../auth-context';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { type Cycle, CycleCard, OpenCycle } from '../../components/cycle-card';

function CyclesScreen() {
  const auth = useAuth();
  const canManage = USER_ADMIN_ROLES.includes(auth.operator?.role ?? '');
  const [cycles, setCycles] = useState<Cycle[] | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [key, setKey] = useState(0);
  const load = useCallback(() => {
    callRpc<Cycle[]>('list_programme_cycles', {}).then(setCycles).catch((err) => setError(errorMessage(err)));
  }, []);
  useEffect(() => { load(); }, [load]);
  const changed = (m: string) => { setMessage(m); load(); setKey((k) => k + 1); };
  const open = cycles?.find((c) => c.status === 'OPEN');
  return (
    <main className="admin-shell">
      <AdminHeader />
      <h1>Ciclos</h1>
      <div className="info-box">
        Um ciclo de cada vez. Em cada ciclo o MEAL escolhe quem recebe: cada agregado recebe 6.000 MT no mesmo cartão e tem de usar tudo dentro do ciclo.
        Ao fechar, o saldo que sobrar expira e fica no relatório como não utilizado.
      </div>
      {message && <div className="success-box">{message}</div>}
      {error && <div className="error-box">{error}</div>}
      {cycles && !open && canManage && <OpenCycle next={cycles.length + 1} onDone={changed} />}
      {cycles && !open && !canManage && <div className="warning-box">Não há nenhum ciclo aberto.</div>}
      {cycles === null && !error && <p>A carregar…</p>}
      {(cycles ?? []).map((c) => <CycleCard key={`${c.cycle_id}-${key}`} cycle={c} canManage={canManage} onChanged={changed} />)}
    </main>
  );
}

export default function CyclesPage() {
  return (
    <AdminGate roles={HOUSEHOLD_ROLES} deniedCode="CYCLE_NOT_ALLOWED">
      <CyclesScreen />
    </AdminGate>
  );
}
