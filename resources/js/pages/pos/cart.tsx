import { Head, Link } from '@inertiajs/react';
import { CheckoutSteps, PosHeader } from '@/components/pos/pos-header';
import { RequireVoucher } from '@/components/pos/require-voucher';
import { useTransaction } from '@/components/pos/transaction';
import { maxPurchase } from '@/lib/copayment';
import { formatMT } from '@/lib/format';

export default function CartStep() {
    const transaction = useTransaction();
    const limit = maxPurchase(transaction.voucher?.balance ?? 0);
    const overLimit = transaction.estimatedSubtotal > limit;

    return (
        <RequireVoucher cart>
            <main className="pos-shell">
                <Head title="Carrinho" />
                <PosHeader title="Carrinho" back="/pos/products" right={formatMT(transaction.voucher?.balance)} />
                <section className="pos-content">
                    <CheckoutSteps current={2} />
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
