import { Head } from '@inertiajs/react';
import { PosHeader } from '@/components/pos/pos-header';
import { ReceiptView } from '@/components/pos/receipt-view';
import type { Receipt } from '@/components/pos/transaction';

// Reimpressão: o recibo é lido de novo da base de dados.
export default function ReceiptPage({ receipt }: { receipt: Receipt }) {
    return (
        <main className="pos-shell">
            <Head title={`Recibo ${receipt.transaction_code}`} />
            <PosHeader title="Recibo" back="/sales" />
            <section className="pos-content">
                <ReceiptView receipt={receipt} />
                <button className="secondary-button no-print" onClick={() => window.print()}>IMPRIMIR</button>
            </section>
        </main>
    );
}
