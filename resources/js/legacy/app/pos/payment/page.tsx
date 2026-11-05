
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useTransaction } from '../transaction-context';
import { PosHeader } from '../../components/pos-header';
import { errorCode, errorMessage } from '../../../lib/errors';
import { maxPurchase } from '../../../lib/copayment';
import { formatMT } from '../../../lib/format';
import { RequireVoucher } from '../require-voucher';
import { PreviewSummary } from '../preview-summary';

const steps = ['Voucher', 'Produtos', 'Carrinho', 'Desconto', 'Pagamento', 'Confirmar'];

export default function PaymentPage() {
  const transaction = useTransaction();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [overLimit, setOverLimit] = useState(false);

  async function calculate() {
    setError(null); setOverLimit(false); setLoading(true);
    try { await transaction.runPreview(); }
    catch (err) { setOverLimit(errorCode(err) === 'COPAYMENT_LIMIT_EXCEEDED'); setError(errorMessage(err)); }
    finally { setLoading(false); }
  }

  useEffect(() => {
    if (!transaction.preview && transaction.voucher && transaction.cart.length > 0) calculate();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <RequireVoucher cart>
      <main className="pos-shell">
        <PosHeader title="Pagamento" back="/pos/discount" />
        <section className="pos-content">
          <div className="checkout-steps no-print">
            {steps.map((label, index) => <div className={index === 4 ? 'checkout-step active' : index < 4 ? 'checkout-step done' : 'checkout-step'} key={label}><span>0{index + 1}</span><strong>{label}</strong></div>)}
          </div>
          <div className="eyebrow">Passo 5 de 6</div>
          <div className="page-heading-row"><div><h1>Confirmar valores</h1><p>O sistema calcula quanto sai do voucher e quanto o beneficiário paga.</p></div><span className="balance-pill">Saldo {formatMT(transaction.voucher?.balance)}</span></div>

          {loading && <div className="loading-card"><span className="loading-dot" /> A calcular pagamento...</div>}
          {error && (overLimit ? (
            <div className="error-box">
              {error} Compra máxima com este voucher: <strong>{formatMT(maxPurchase(transaction.voucher?.balance ?? 0))}</strong>.
              <Link href="/pos/cart" className="secondary-button link-button">VOLTAR AO CARRINHO</Link>
            </div>
          ) : (
            <div className="error-box">{error}<button className="secondary-button" onClick={calculate}>TENTAR DE NOVO</button></div>
          ))}

          {transaction.preview && !loading && (
            <>
              <PreviewSummary preview={transaction.preview} />
              <Link href="/pos/confirmation" className="primary-button link-button operator-primary">REVER E CONFIRMAR <span>→</span></Link>
            </>
          )}
          {transaction.voucher && <p className="small-note">Saldo antes da venda: {formatMT(transaction.preview?.balance_before ?? transaction.voucher.balance)}</p>}
        </section>
      </main>
    </RequireVoucher>
  );
}