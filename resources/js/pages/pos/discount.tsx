import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { CheckoutSteps, PosHeader } from '@/components/pos/pos-header';
import { RequireVoucher } from '@/components/pos/require-voucher';
import { useTransaction, type DiscountType } from '@/components/pos/transaction';
import { formatMT } from '@/lib/format';

const CHOICES: [DiscountType, string, string, string][] = [
    ['none', '✓', 'Sem desconto', 'Venda pelo preço normal'],
    ['PERCENTAGE', '%', 'Percentagem', 'Ex.: 5% ou 10%'],
    ['FIXED_AMOUNT', 'MT', 'Valor fixo', 'Desconto em MT'],
];

export default function DiscountStep() {
    const transaction = useTransaction();
    const [value, setValue] = useState(transaction.discountValue ? String(transaction.discountValue) : '');

    function updateValue(next: string) {
        setValue(next);
        transaction.setDiscount(transaction.discountType, Number(next) || 0);
    }

    return (
        <RequireVoucher cart>
            <main className="pos-shell">
                <Head title="Desconto" />
                <PosHeader title="Desconto" back="/pos/cart" />
                <section className="pos-content">
                    <CheckoutSteps current={3} />
                    <h1>Aplicar desconto</h1>
                    <p>Registe apenas o desconto que o agrodealer está a oferecer. O valor final será validado pelo servidor.</p>

                    <div className="discount-choice-grid">
                        {CHOICES.map(([type, mark, label, help]) => (
                            <button key={type} type="button" className={transaction.discountType === type ? 'discount-choice active' : 'discount-choice'}
                                onClick={() => transaction.setDiscount(type, Number(value) || 0)}>
                                <span>{mark}</span><strong>{label}</strong><small>{help}</small>
                            </button>
                        ))}
                    </div>

                    {transaction.discountType !== 'none' && (
                        <div className="checkout-card discount-input-card">
                            <label className="form-label" htmlFor="discount-value">{transaction.discountType === 'PERCENTAGE' ? 'Percentagem de desconto' : 'Valor do desconto em MT'}</label>
                            <input id="discount-value" className="input large-input" type="number" min="0" step="any" inputMode="decimal" placeholder="0" value={value} onChange={(e) => updateValue(e.target.value)} />
                        </div>
                    )}

                    <div className="checkout-card checkout-total">
                        <div><span>Subtotal estimado</span><strong>{formatMT(transaction.estimatedSubtotal)}</strong></div>
                        <Link href="/pos/payment" className="primary-button link-button operator-primary">CONTINUAR <span>→</span></Link>
                    </div>
                </section>
            </main>
        </RequireVoucher>
    );
}
