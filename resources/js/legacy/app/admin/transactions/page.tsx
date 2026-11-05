
import { AdminGate } from '../../components/admin-gate';
import { HOUSEHOLD_ROLES } from '../../auth-context';
import { AdminHeader } from '../../components/admin-header';
import { TransactionsList } from '../../components/transactions-list';

export default function TransactionsPage() {
  return <AdminGate roles={HOUSEHOLD_ROLES} deniedCode="TRANSACTIONS_NOT_ALLOWED"><main className="admin-shell"><AdminHeader /><TransactionsList eyebrow="MEAL · MONITORIA" /></main></AdminGate>;
}
