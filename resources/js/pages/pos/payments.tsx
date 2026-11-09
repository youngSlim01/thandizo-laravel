import { Head } from '@inertiajs/react';
import { PosHeader } from '@/components/pos/pos-header';
import { formatDate, formatMT } from '@/lib/format';

// Para o gestor do comerciante (o dono da loja): quanto lhe devem em cada
// fase do pagamento e as suas reconciliações. O pagamento faz-se no sistema
// da organização depois de as Finanças validarem.

type Stage = 'not_reconciled' | 'in_meal' | 'in_finance' | 'validated';
type Settlement = {
    id: string; settlement_code: string; period_start: string; period_end: string;
    transactions: number; amount: number; stage: Exclude<Stage, 'not_reconciled'>; created_at: string;
};
type Props = { stages: Record<Stage, number>; settlements: Settlement[] };

const STAGES: { key: Stage; label: string; hint: string }[] = [
    { key: 'not_reconciled', label: 'Por reconciliar', hint: 'Vendas que o MEAL ainda não juntou numa reconciliação' },
    { key: 'in_meal', label: 'No MEAL', hint: 'Em revisão ou devolvidas para corrigir' },
    { key: 'in_finance', label: 'Nas Finanças', hint: 'À espera de validação' },
    { key: 'validated', label: 'Validado', hint: 'Entregue ao pagamento da organização' },
];
const STAGE_LABEL: Record<Settlement['stage'], string> = { in_meal: 'No MEAL', in_finance: 'Nas Finanças', validated: 'Validada' };

export default function Payments({ stages, settlements }: Props) {
    const owed = stages.not_reconciled + stages.in_meal + stages.in_finance;

    return (
        <main className="pos-shell">
            <Head title="Pagamentos" />
            <PosHeader title="Pagamentos" back="/" />
            <section className="pos-content sales-content">
                <div className="eyebrow">A sua loja</div>
                <div className="sales-title-row">
                    <div><h1>Pagamentos</h1><p>A parte do voucher das suas vendas. O copagamento já o recebeu ao balcão.</p></div>
                </div>

                <div className="pay-owed"><span>Ainda por pagar</span><strong>{formatMT(owed)}</strong></div>

                <div className="pay-stages">
                    {STAGES.map((s) => (
                        <div key={s.key} className={`pay-stage pay-${s.key}`}>
                            <span>{s.label}</span><strong>{formatMT(stages[s.key])}</strong><small>{s.hint}</small>
                        </div>
                    ))}
                </div>

                <h2 className="pay-title">Reconciliações</h2>
                {settlements.length === 0 && <div className="sales-empty"><strong>Ainda não há reconciliações</strong><span>O MEAL junta as vendas dos dias já fechados numa reconciliação.</span></div>}
                <div className="sales-list">
                    {settlements.map((s) => (
                        <article className="sale-record" key={s.id}>
                            <div className="sale-record-head">
                                <div className="sale-code"><div><strong>{s.settlement_code}</strong><small>{formatDate(s.period_start)} – {formatDate(s.period_end)} · {s.transactions} venda(s)</small></div></div>
                                <span className={`sale-status pay-badge-${s.stage}`}>{STAGE_LABEL[s.stage]}</span>
                            </div>
                            <div className="sale-record-values"><div><span>A pagar</span><strong>{formatMT(s.amount)}</strong></div></div>
                        </article>
                    ))}
                </div>
            </section>
        </main>
    );
}
