import { Head, Link } from '@inertiajs/react';
import { CheckoutSteps, PosHeader } from '@/components/pos/pos-header';
import { RequireVoucher } from '@/components/pos/require-voucher';
import { useTransaction, type Product } from '@/components/pos/transaction';
import { formatMT } from '@/lib/format';

function productMark(name: string) {
    const n = name.toLowerCase();
    if (n.includes('milho')) return '🌽';
    if (n.includes('feijão') || n.includes('feijao')) return '🫘';
    if (n.includes('arroz')) return '🌾';
    if (n.includes('adubo') || n.includes('fert')) return '▦';
    return '＋';
}

// Produtos do catálogo deste agrodealer, ao preço do seu distrito.
export default function ProductsStep({ products }: { products: Product[] }) {
    const transaction = useTransaction();

    return (
        <RequireVoucher>
            <main className="pos-shell">
                <Head title="Insumos" />
                <PosHeader title="Insumos" back="/pos/voucher" right={formatMT(transaction.voucher?.balance)} />
                <section className="pos-content">
                    <CheckoutSteps current={1} />
                    <div className="page-heading-row">
                        <div><h1>Selecionar insumos</h1><p>Escolha os produtos e ajuste as quantidades.</p></div>
                        <span className="balance-pill">Saldo {formatMT(transaction.voucher?.balance)}</span>
                    </div>

                    {products.length === 0 && <div className="error-box">Não há insumos no catálogo deste comerciante com preço no distrito. Fale com o gestor do comerciante ou com a equipa MEAL.</div>}

                    <div className="product-page-layout">
                        <div className="product-grid product-grid-refined">{products.map((product) => {
                            const quantity = transaction.cart.find((item) => item.id === product.id)?.quantity ?? 0;
                            return (
                                <div className={quantity > 0 ? 'product-card selected' : 'product-card'} key={product.id}>
                                    <div className="product-top"><div className="product-symbol product-emoji">{productMark(product.name)}</div>{quantity > 0 && <span className="selected-badge">{quantity}</span>}</div>
                                    <strong>{product.name}</strong>
                                    <p>{formatMT(product.listPrice)} / {product.unit}</p>
                                    <div className="product-add-row">
                                        <button className="product-add-button" onClick={() => transaction.addProduct(product)} aria-label={`Adicionar ${product.name}`}>＋</button>
                                    </div>
                                </div>
                            );
                        })}</div>

                        <aside className="mini-cart-card">
                            <div className="mini-cart-head">
                                <div><strong>Carrinho</strong><span>{transaction.cart.length} produto(s)</span></div>
                                {transaction.cart.length > 0 && <button onClick={transaction.clearCart}>Limpar</button>}
                            </div>
                            {transaction.cart.length === 0 ? <div className="mini-cart-empty">Selecione um insumo para começar.</div> : transaction.cart.map((item) => (
                                <div className="mini-cart-row" key={item.id}><span><strong>{item.name}</strong><small>{item.quantity} × {formatMT(item.listPrice)}</small></span><strong>{formatMT(item.listPrice * item.quantity)}</strong></div>
                            ))}
                            <div className="mini-cart-total"><span>Subtotal</span><strong>{formatMT(transaction.estimatedSubtotal)}</strong></div>
                            {transaction.cart.length > 0 && <Link href="/pos/cart" className="primary-button link-button operator-primary">VER CARRINHO <span>→</span></Link>}
                        </aside>
                    </div>
                </section>
            </main>
        </RequireVoucher>
    );
}
