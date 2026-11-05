import { Head } from '@inertiajs/react';
import { CycleCard, type Cycle } from '@/components/cycle-card';
import { FinanceLayout } from '@/components/finance-layout';

// Relatórios de fim de ciclo (PDF / Excel) para as Finanças, só leitura.
// Abrir, recarregar e fechar ciclos fica com o MEAL.
export default function FinanceReports({ cycles }: { cycles: Cycle[] }) {
    return (
        <FinanceLayout>
            <Head title="Relatórios" />
            <div className="admin-page-heading"><div><div className="eyebrow">FINANCE · RELATÓRIOS</div><h1>Relatórios</h1>
                <p>Relatório de cada ciclo: valor atribuído, gasto, copagamento, não utilizado, por distrito, agrodealer e produto.</p>
            </div></div>
            {cycles.length === 0 && <div className="recent-empty">Ainda não há ciclos. O primeiro relatório aparece quando o MEAL abrir um ciclo.</div>}
            {cycles.map((c) => <CycleCard key={c.cycle_id} cycle={c} canManage={false} />)}
        </FinanceLayout>
    );
}
