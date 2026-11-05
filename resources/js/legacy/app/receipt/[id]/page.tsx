
import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { OperatorGate } from '../../components/operator-gate';
import { PosHeader } from '../../components/pos-header';
import { ReceiptView } from '../../components/receipt-view';
import type { Receipt } from '../../pos/transaction-context';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';

// Reprint: the receipt is read again from the database.
function ReceiptPage() {
  const { id } = useParams<{ id: string }>();
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    callRpc<Receipt>('get_transaction_receipt', { p_transaction_id: id })
      .then(setReceipt)
      .catch((err) => setError(errorMessage(err)));
  }, [id]);

  return (
    <main className="pos-shell">
      <PosHeader title="Recibo" back="/sales" />
      <section className="pos-content">
        {error && <div className="error-box">{error}</div>}
        {!receipt && !error && <p>A carregar…</p>}
        {receipt && (
          <>
            <ReceiptView receipt={receipt} />
            <button className="secondary-button no-print" onClick={() => window.print()}>IMPRIMIR</button>
          </>
        )}
      </section>
    </main>
  );
}

export default function Page() {
  return (
    <OperatorGate>
      <ReceiptPage />
    </OperatorGate>
  );
}
