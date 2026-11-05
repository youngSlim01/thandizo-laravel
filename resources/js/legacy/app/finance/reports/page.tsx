
import { useEffect, useState } from 'react';
import { FinanceGate } from '../../components/finance-gate';
import { FinanceHeader } from '../../components/finance-header';
import { type Cycle, CycleCard } from '../../components/cycle-card';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';

// End-of-cycle reports (PDF / Excel) for Finance: read-only, every figure from
// cycle_summary. Opening, recharging and closing cycles stays with MEAL.
function ReportsScreen() {
  const [cycles, setCycles] = useState<Cycle[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    callRpc<Cycle[]>('list_programme_cycles', {}).then(setCycles).catch((err) => setError(errorMessage(err)));
  }, []);
  return <>
    <div className="admin-page-heading">
      <div><div className="eyebrow">FINANCE · RELATÓRIOS</div><h1>Relatórios</h1>
        <p>Relatório de cada ciclo: valor atribuído, gasto, copagamento, não utilizado, por distrito, agrodealer e produto.</p>
      </div>
    </div>
    {error && <div className="error-box">{error}</div>}
    {cycles === null && !error && <div className="loading-card"><span className="loading-dot" /> A carregar ciclos…</div>}
    {cycles?.length === 0 && <div className="recent-empty">Ainda não há ciclos. O primeiro relatório aparece quando o MEAL abrir um ciclo.</div>}
    {(cycles ?? []).map((c) => <CycleCard key={c.cycle_id} cycle={c} canManage={false} onChanged={() => undefined} />)}
  </>;
}

export default function FinanceReportsPage() {
  return <FinanceGate>
    <div className="finance-app">
      <FinanceHeader />
      <main className="finance-main"><ReportsScreen /></main>
    </div>
  </FinanceGate>;
}
