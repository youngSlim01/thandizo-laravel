
import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useTransaction } from '../transaction-context';
import { PosHeader } from '../../components/pos-header';
import { ReceiptView } from '../../components/receipt-view';
import { errorCode, errorMessage } from '../../../lib/errors';
import { RequireVoucher } from '../require-voucher';
import { formatMT } from '../../../lib/format';
import { PreviewSummary } from '../preview-summary';

export default function ConfirmationPage() {
  const transaction = useTransaction();
  const router = useRouter();
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [outdated, setOutdated] = useState(false);

  async function confirmSale() {
    setError(null); setOutdated(false); setSubmitting(true);
    try { await transaction.confirm(); }
    catch (err) { setOutdated(errorCode(err) === 'PREVIEW_OUTDATED'); setError(errorMessage(err)); }
    finally { setSubmitting(false); }
  }

  function newSale() {
    transaction.resetTransaction();
    router.push('/pos/voucher');
  }

  if (transaction.result) {
    return (
      <main className="pos-shell">
        <PosHeader title="Venda confirmada" />
        <section className="pos-content success-page">
          <div className="success-mark no-print">✓</div>
          <div className="success-heading no-print"><span>Transação concluída</span><small>O recibo está pronto para impressão.</small></div>
          <ReceiptView receipt={transaction.result.receipt} />
          <div className="success-actions no-print">
            <button className="secondary-button" onClick={() => window.print()}>IMPRIMIR RECIBO</button>
            <button className="primary-button" onClick={newSale}>NOVA VENDA</button>
          </div>
          <Link href="/" className="quick-link no-print">Voltar ao início →</Link>
        </section>
      </main>
    );
  }

  const voucher = transaction.voucher;
  return (
    <RequireVoucher cart>
      <main className="pos-shell">
        <PosHeader title="Confirmar" back="/pos/payment" />
        <section className="pos-content">
          <div className="checkout-steps no-print">
            {['Voucher','Produtos','Carrinho','Desconto','Pagamento','Confirmar'].map((label, index) => (
              <div className={index === 5 ? 'checkout-step active' : 'checkout-step done'} key={label}><span>0{index + 1}</span><strong>{label}</strong></div>
            ))}
          </div>
          <div className="eyebrow">Passo 6 de 6</div>
          <div className="page-heading-row"><div><h1>Confirmar venda</h1><p>Mostre o resumo ao beneficiário antes de finalizar a transação.</p></div><span className="balance-pill">Voucher {voucher?.code}</span></div>

          {voucher && (
            <div className="beneficiary-card">
              <div className="beneficiary-avatar">⌂</div>
              <div><span>Beneficiário</span><strong>{voucher.representativeName}</strong><small>Voucher: {voucher.code}</small></div>
            </div>
          )}

          <div className="confirmation-products">
            <div className="section-label">Produtos seleccionados</div>
            {transaction.cart.map((item) => (
              <div className="amount-row" key={item.id}><span>{item.name} × {item.quantity}</span><strong>{formatMT(item.listPrice * item.quantity)}</strong></div>
            ))}
          </div>

          {error && <div className={outdated ? 'warning-box' : 'error-box'}>{error}</div>}
          {transaction.preview ? (
            <>
              <PreviewSummary preview={transaction.preview} />
              <button className="primary-button confirm-button operator-primary" onClick={confirmSale} disabled={submitting}>
                {submitting ? 'A CONFIRMAR…' : outdated ? 'CONFIRMAR COM OS NOVOS VALORES' : 'CONFIRMAR VENDA'}
              </button>
            </>
          ) : (
            <Link href="/pos/payment" className="primary-button link-button">CALCULAR PAGAMENTO</Link>
          )}
        </section>
      </main>
    </RequireVoucher>
  );
}