import { Head } from '@inertiajs/react';
import { BackOfficeLayout } from '@/components/finance-layout';
import { TransactionsList } from '@/components/transactions-list';

// Transações do programa, para o MEAL (monitoria) e as Finanças.
export default function Transactions({ area }: { area: 'meal' | 'finance' }) {
    return (
        <BackOfficeLayout>
            <Head title="Transações" />
            <TransactionsList eyebrow={area === 'finance' ? 'FINANCE · TRANSAÇÕES' : 'MEAL · MONITORIA'} />
        </BackOfficeLayout>
    );
}
