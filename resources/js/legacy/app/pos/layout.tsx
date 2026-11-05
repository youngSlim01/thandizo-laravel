
import { OperatorGate } from '../components/operator-gate';
import { TransactionProvider } from './transaction-context';

// Every POS screen requires a logged-in operator with a POS selected, and
// shares the same sale state while the operator moves through the workflow.
export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <OperatorGate>
      <TransactionProvider>{children}</TransactionProvider>
    </OperatorGate>
  );
}
