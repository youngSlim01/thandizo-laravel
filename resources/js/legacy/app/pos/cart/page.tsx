
import Link from 'next/link';
import { useTransaction } from '../transaction-context';
import { PosHeader } from '../../components/pos-header';
import { formatMT } from '../../../lib/format';
import { RequireVoucher } from '../require-voucher';
import { maxPurchase } from '../../../lib/copayment';

const steps = ['Voucher', 'Produtos', 'Carrinho', 'Desconto', 'Pagamento', 'Confirmar'];

export default function CartPage() {
  const transaction = useTransaction();
  const limit = maxPurchase(transaction.voucher?.balance ?? 0);
  const overLimit = transaction.estimatedSubtotal > limit;

  return (
    <RequireVoucher cart>
      <main className="pos-shell">
        <PosHeader title="Carrinho" back="/pos/products" right={formatMT(transaction.voucher?.balance)} />
        <section className="pos-content">
          <div className="checkout-steps no-print">
            {steps.map((label, index) => (
              <div className={index === 2 ? 'checkout-step active' : index < 2 ? 'checkout-step done' : 'checkout-step'} key={label}>
                <span>0{index + 1}</span><strong>{label}</strong>
              </div>
            ))}
          </div>
          <div className="eyebrow">Passo 3 de 6</div>
          <div className="page-heading-row">
            <div><h1>Rever produtos</h1><p>Confirme quantidades antes de avançar.</p></div>
            <span className="balance-pill">Saldo {formatMT(transaction.voucher?.balance)}</span>
          </div>

          <div className="cart-card modern-cart-card">
            <div className="section-heading"><div><span className="section-label">Itens da venda</span><small>{transaction.cart.length} produto(s)</small></div></div>
            {transaction.cart.map((item) => (
              <div className="cart-item" key={item.id}>
                <div className="cart-item-main">
                  <div className="product-symbol small-symbol">▦</div>
                  <div><strong>{item.name}</strong><span>{formatMT(item.listPrice)} por unidade</span></div>
                </div>
                <div className="cart-item-actions">
                  <div className="stepper small">
                    <button onClick={() => transaction.changeQuantity(item.id, -1)} aria-label={`Reduzir ${item.name}`}>−</button>
                    <strong>{item.quantity}</strong>
                    <button onClick={() => transaction.changeQuantity(item.id, 1)} aria-label={`Aumentar ${item.name}`}>+</button>
                  </div>
                  <strong className="cart-item-total">{formatMT(item.listPrice * item.quantity)}</strong>
                </div>
              </div>
            ))}
          </div>

          {overLimit ? (
            <div className="warning-box">
              O subtotal passa a compra máxima deste voucher ({formatMT(limit)}). O dinheiro não pode passar de 20% do total:
              retire produtos ou reduza quantidades (ou aplique um desconto no passo seguinte).
            </div>
          ) : (
            <p className="small-note">Compra máxima com este voucher: {formatMT(limit)} (o dinheiro não pode passar de 20% do total).</p>
          )}

          <div className="checkout-card checkout-total">
            <div><span>Subtotal estimado</span><strong>{formatMT(transaction.estimatedSubtotal)}</strong></div>
            <Link href="/pos/discount" className="primary-button link-button operator-primary">CONTINUAR <span>→</span></Link>
          </div>
        </section>
      </main>
    </RequireVoucher>
  );
}