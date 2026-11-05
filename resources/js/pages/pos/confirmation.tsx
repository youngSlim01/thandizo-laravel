import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckoutSteps, PosHeader } from '@/components/pos/pos-header';
import { PreviewSummary } from '@/components/pos/preview-summary';
import { ReceiptView } from '@/components/pos/receipt-view';
import { RequireVoucher } from '@/components/pos/require-voucher';
import { useTransaction } from '@/components/pos/transaction';
import { formatMT } from '@/lib/format';
import { RequestError, errorText } from '@/lib/http';

export default function ConfirmationStep() {
    const transaction = useTransaction();
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [outdated, setOutdated] = useState(false);

    async function confirmSale() {
        setError(null); setOutdated(false); setSubmitting(true);
        try { await transaction.confirm(); }
        catch (err) { setOutdated(err instanceof RequestError && err.code === 'PREVIEW_OUTDATED'); setError(errorText(err)); }
        finally { setSubmitting(false); }
    }

    function newSale() {
        transaction.resetTransaction();
        router.visit('/pos/voucher');
    }

    if (transaction.result) {
        return (
            <main className="pos-shell">
                <Head title="Venda confirmada" />
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
                <Head title="Confirmar" />
                <PosHeader title="Confirmar" back="/pos/payment" />
                <section className="pos-content">
                    <CheckoutSteps current={5} />
                    <div className="page-heading-row">
                        <div><h1>Confirmar venda</h1><p>Mostre o resumo ao beneficiário antes de finalizar a transação.</p></div>
                        <span className="balance-pill">Voucher {voucher?.code}</span>
                    </div>

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
