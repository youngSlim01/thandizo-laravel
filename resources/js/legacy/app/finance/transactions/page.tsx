
import { FinanceGate } from '../../components/finance-gate';
import { FinanceHeader } from '../../components/finance-header';
import { TransactionsList } from '../../components/transactions-list';

export default function FinanceTransactionsPage() {
  return <FinanceGate>
    <div className="finance-app">
      <FinanceHeader />
      <main className="finance-main"><TransactionsList eyebrow="FINANCE · TRANSAÇÕES" /></main>
    </div>
  </FinanceGate>;
}
