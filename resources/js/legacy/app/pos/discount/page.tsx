
import { useState } from 'react';
import Link from 'next/link';
import { DiscountType, useTransaction } from '../transaction-context';
import { PosHeader } from '../../components/pos-header';
import { formatMT } from '../../../lib/format';
import { RequireVoucher } from '../require-voucher';

const steps = ['Voucher', 'Produtos', 'Carrinho', 'Desconto', 'Pagamento', 'Confirmar'];

export default function DiscountPage() {
  const transaction = useTransaction();
  const [value, setValue] = useState(transaction.discountValue ? String(transaction.discountValue) : '');

  function updateType(type: DiscountType) {
    transaction.setDiscount(type, Number(value) || 0);
  }
  function updateValue(next: string) {
    setValue(next);
    transaction.setDiscount(transaction.discountType, Number(next) || 0);
  }

  return (
    <RequireVoucher cart>
      <main className="pos-shell">
        <PosHeader title="Desconto" back="/pos/cart" />
        <section className="pos-content">
          <div className="checkout-steps no-print">
            {steps.map((label, index) => <div className={index === 3 ? 'checkout-step active' : index < 3 ? 'checkout-step done' : 'checkout-step'} key={label}><span>0{index + 1}</span><strong>{label}</strong></div>)}
          </div>
          <div className="eyebrow">Passo 4 de 6</div>
          <h1>Aplicar desconto</h1>
          <p>Registe apenas o desconto que o agrodealer está a oferecer. O valor final será validado pelo servidor.</p>

          <div className="discount-choice-grid">
            {[
              ['none','Sem desconto','Venda pelo preço normal'],
              ['PERCENTAGE','Percentagem','Ex.: 5% ou 10%'],
              ['FIXED_AMOUNT','Valor fixo','Desconto em MT'],
            ].map(([type,label,help]) => (
              <button key={type} type="button" className={transaction.discountType === type ? 'discount-choice active' : 'discount-choice'} onClick={() => updateType(type as DiscountType)}>
                <span>{type === 'none' ? '✓' : type === 'PERCENTAGE' ? '%' : 'MT'}</span><strong>{label}</strong><small>{help}</small>
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