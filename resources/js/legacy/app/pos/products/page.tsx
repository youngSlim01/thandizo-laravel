
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useTransaction, Product } from '../transaction-context';
import { PosHeader } from '../../components/pos-header';
import { callRpc } from '../../../lib/supabase';
import { useAuth } from '../../auth-context';
import { errorMessage } from '../../../lib/errors';
import { formatMT } from '../../../lib/format';
import { RequireVoucher } from '../require-voucher';

// Products of this agrodealer's catalogue, at the price of its district.
type CatalogueProduct = { product_id: string; code: string; name: string; unit: string; in_catalogue: boolean; unit_price: number | null };

function productMark(name: string) {
  const n = name.toLowerCase();
  if (n.includes('milho')) return '🌽';
  if (n.includes('feijão') || n.includes('feijao')) return '🫘';
  if (n.includes('arroz')) return '🌾';
  if (n.includes('adubo') || n.includes('fert')) return '▦';
  return '＋';
}

export default function ProductsPage() {
  const transaction = useTransaction();
  const auth = useAuth();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!auth.operator?.agrodealerId) return;
    let cancelled = false;
    (async () => {
      try {
        const catalogue = await callRpc<{ products: CatalogueProduct[] }>('get_agrodealer_catalogue', { p_agrodealer_id: auth.operator?.agrodealerId ?? null });
        if (cancelled) return;
        setProducts(catalogue.products.filter((p) => p.in_catalogue && p.unit_price !== null)
          .map((p) => ({ id: p.product_id, code: p.code, name: p.name, unit: p.unit, listPrice: Number(p.unit_price) })));
      } catch (err) {
        if (cancelled) return;
        setError(errorMessage(err));
      }
      setLoading(false);
    })();
    return () => { cancelled = true; };
  }, [auth.operator?.agrodealerId]);

  return (
    <RequireVoucher>
      <main className="pos-shell">
        <PosHeader title="Insumos" back="/pos/voucher" right={formatMT(transaction.voucher?.balance)} />
        <section className="pos-content">
          <div className="checkout-steps no-print">
            {['Voucher','Produtos','Carrinho','Desconto','Pagamento','Confirmar'].map((label, index) => (
              <div className={index === 1 ? 'checkout-step active' : index < 1 ? 'checkout-step done' : 'checkout-step'} key={label}>
                <span>0{index + 1}</span><strong>{label}</strong>
              </div>
            ))}
          </div>
          <div className="eyebrow">Passo 2 de 6</div>
          <div className="page-heading-row">
            <div><h1>Selecionar insumos</h1><p>Escolha os produtos e ajuste as quantidades.</p></div>
            <span className="balance-pill">Saldo {formatMT(transaction.voucher?.balance)}</span>
          </div>

          {loading && <div className="loading-card"><span className="loading-dot" /> A carregar insumos...</div>}
          {error && <div className="error-box">{error}</div>}
          {!loading && !error && products.length === 0 && <div className="error-box">Não há insumos no catálogo deste agrodealer com preço no distrito. Fale com o gestor do agrodealer ou com a equipa MEAL.</div>}

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
              <div className="mini-cart-head"><div><strong>Carrinho</strong><span>{transaction.cart.length} produto(s)</span></div>{transaction.cart.length > 0 && <button onClick={() => transaction.cart.forEach((item) => transaction.changeQuantity(item.id, -item.quantity))}>Limpar</button>}</div>
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