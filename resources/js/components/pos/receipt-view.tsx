
import type { Receipt } from './transaction';
import { useBrand } from '@/lib/brand';
import { formatDateTime, formatMT } from '@/lib/format';

// Recibo para imprimir, feito só com os dados devolvidos pelo servidor.
export function ReceiptView({ receipt }: { receipt: Receipt }) {
  const brand = useBrand();
  const client = [brand.client, brand.programme].filter(Boolean).join(' · ');
  return (
    <div className="receipt">
      <div className="receipt-head">
        <strong>THANDIZO</strong>
        {client && <div>{client}</div>}
        <div>{receipt.agrodealer_name}</div>
        <div>POS {receipt.pos_code} · {receipt.operator_name}</div>
        <div>{formatDateTime(receipt.confirmed_at)}</div>
        <div className="receipt-code">{receipt.transaction_code}</div>
        {receipt.voided && (
          <div className="receipt-voided">
            ANULADA {receipt.voided_at ? `· ${formatDateTime(receipt.voided_at)}` : ''}
            {receipt.void_reason ? <div>Motivo: {receipt.void_reason}</div> : null}
          </div>
        )}
      </div>

      <div className="receipt-section">
        <div>Voucher: {receipt.voucher_code}</div>
        <div>Agregado: {receipt.representative_name} ({receipt.household_code})</div>
      </div>

      <div className="receipt-section">
        {receipt.items.map((item) => (
          <div className="amount-row" key={item.product_code}>
            <span>{item.product_name}<br /><small>{Number(item.quantity)} {item.unit} × {formatMT(item.unit_price)}</small></span>
            <strong>{formatMT(item.line_total)}</strong>
          </div>
        ))}
      </div>

      <div className="receipt-section">
        <div className="amount-row"><span>Subtotal</span><strong>{formatMT(receipt.subtotal)}</strong></div>
        <div className="amount-row"><span>Desconto</span><strong>{formatMT(receipt.discount_amount)}</strong></div>
        <div className="amount-row"><span>Total</span><strong>{formatMT(receipt.total_amount)}</strong></div>
        <div className="amount-row"><span>Pago pelo voucher</span><strong>{formatMT(receipt.voucher_amount)}</strong></div>
        <div className="amount-row emphasis"><span>Co-pagamento</span><strong>{formatMT(receipt.copayment_amount)}</strong></div>
        {receipt.voucher_balance_after !== null && (
          <div className="amount-row"><span>Saldo do voucher após a venda</span><strong>{formatMT(receipt.voucher_balance_after)}</strong></div>
        )}
      </div>
    </div>
  );
}
