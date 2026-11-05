import { formatMT } from '@/lib/format';
import type { Preview } from './transaction';

export function PreviewSummary({ preview }: { preview: Preview }) {
    return (
        <div className="payment-summary">
            <div className="summary-card">
                <div className="summary-card-title">Resumo da compra</div>
                <div className="amount-row"><span>Subtotal</span><strong>{formatMT(preview.subtotal)}</strong></div>
                <div className="amount-row"><span>Desconto</span><strong>- {formatMT(preview.discount_amount)}</strong></div>
                <div className="amount-row total-row"><span>Total da compra</span><strong>{formatMT(preview.total_amount)}</strong></div>
            </div>
            <div className="summary-card voucher-payment-card">
                <div className="summary-card-title">Como será pago</div>
                <div className="payment-split">
                    <div><span>Voucher</span><strong>{formatMT(preview.voucher_amount)}</strong></div>
                    <div><span>Dinheiro</span><strong>{formatMT(preview.copayment_amount)}</strong></div>
                </div>
                <div className="balance-after"><span>Saldo do voucher após a venda</span><strong>{formatMT(preview.balance_after)}</strong></div>
            </div>
        </div>
    );
}
