
import { FormEvent, useCallback, useState } from 'react';
import Link from 'next/link';
import { useTransaction } from '../transaction-context';
import { PosHeader } from '../../components/pos-header';
import { callRpc } from '../../../lib/supabase';
import { errorMessage } from '../../../lib/errors';
import { formatMT } from '../../../lib/format';
import { cardCodeLooksValid } from '../../../lib/voucher-code';
import { QrScanner } from '../../components/qr-scanner';

type LookupResult = {
  success: boolean; found: boolean; message?: string; voucher_code: string;
  voucher_status: string; household_status?: string; household_code: string; representative_name: string; current_balance: number;
};

const steps = ['Voucher', 'Produtos', 'Carrinho', 'Desconto', 'Pagamento', 'Confirmar'];

export default function VoucherPage() {
  const transaction = useTransaction();
  const [code, setCode] = useState(transaction.voucher?.code ?? '');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [scanning, setScanning] = useState(false);

  async function lookup(value: string) {
    setError(null);
    if (!cardCodeLooksValid(value)) { setError(errorMessage('VOUCHER_CODE_CHECK_FAILED')); return; }
    setLoading(true);
    try {
      const data = await callRpc<LookupResult>('lookup_voucher_for_pos', { p_voucher_code: value.trim() });
      if (!data.found) setError(errorMessage(data.message ?? 'VOUCHER_NOT_FOUND'));
      else if (data.voucher_status === 'PENDING') setError(errorMessage('VOUCHER_PENDING'));
      else if (data.voucher_status === 'REPLACED') setError(errorMessage('VOUCHER_REPLACED'));
      else if (data.voucher_status !== 'ACTIVE') setError(errorMessage('VOUCHER_NOT_ACTIVE'));
      else if (data.household_status && data.household_status !== 'ACTIVE') setError(errorMessage('HOUSEHOLD_INACTIVE'));
      // Co-payment only complements a voucher; with no balance there is no purchase.
      else if (Number(data.current_balance) <= 0) setError(errorMessage('VOUCHER_BALANCE_EMPTY'));
      else transaction.setVoucher({ code: data.voucher_code, householdCode: data.household_code, representativeName: data.representative_name, balance: Number(data.current_balance) });
    } catch (err) { setError(errorMessage(err)); }
    finally { setLoading(false); }
  }

  function validateVoucher(event: FormEvent) {
    event.preventDefault();
    lookup(code);
  }

  // The QR code holds only the voucher code. Anything else is refused before
  // it reaches the lookup.
  const scanned = useCallback((text: string) => {
    setScanning(false);
    const value = text.trim().toUpperCase();
    if (!/^[A-Z0-9-]{4,40}$/.test(value)) { setError(errorMessage('QR_NOT_A_VOUCHER')); return; }
    setCode(value);
    lookup(value);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const voucher = transaction.voucher;

  return (
    <main className="pos-shell">
      <PosHeader title="Nova venda" back="/" />
      <section className="pos-content">
        <div className="checkout-steps no-print">
          {steps.map((label, index) => (
            <div className={index === 0 ? 'checkout-step active' : 'checkout-step'} key={label}>
              <span>0{index + 1}</span><strong>{label}</strong>
            </div>
          ))}
        </div>
        <div className="eyebrow">Passo 1 de 6</div>
        <div className="page-heading-row">
          <div><h1>Consultar voucher</h1><p>Introduza ou leia o código do voucher do agregado familiar.</p></div>
          <span className="balance-pill">POS {transaction.voucher ? 'activo' : 'pronto'}</span>
        </div>

        <div className="voucher-entry-layout">
          <form onSubmit={validateVoucher} className="voucher-entry-card">
            <div className="voucher-card-title"><span className="voucher-card-icon">▣</span><div><strong>Código do voucher</strong><small>Digite o código exactamente como aparece no cartão.</small></div></div>
            <label className="form-label" htmlFor="voucher-code">Código</label>
            <input id="voucher-code" className="input voucher-input" value={code} onChange={(event) => setCode(event.target.value.toUpperCase())}
              placeholder="VCH-XXXX-XXXX" autoCapitalize="characters" autoComplete="off" required />
            <div className="voucher-actions">
              <button className="primary-button" type="submit" disabled={loading}>{loading ? 'A VALIDAR…' : 'CONSULTAR VOUCHER'}</button>
              <button className="secondary-button qr-button" type="button" disabled={loading} onClick={() => { setError(null); setScanning(true); }}>
                <span aria-hidden="true">▣</span> LER QR CODE
              </button>
            </div>
            {error && <div className="error-box">{error}</div>}
          </form>
          <div className="voucher-help-card">
            <div className="help-icon">i</div><strong>Dica</strong>
            <p>Confirme o código com o beneficiário antes de continuar. O saldo apresentado é o saldo disponível no momento da consulta.</p>
          </div>
        </div>

        {scanning && <QrScanner onResult={scanned} onClose={() => setScanning(false)} />}

        {voucher && !error && (
          <div className="validated-voucher">
            <div className="validated-voucher-head"><span className="validated-icon">✓</span><div><strong>Voucher válido</strong><small>Beneficiário encontrado</small></div><span className="status">ACTIVO</span></div>
            <div className="validated-voucher-body">
              <div><span>Beneficiário</span><strong>{voucher.representativeName}</strong><small>Agregado {voucher.householdCode}</small></div>
              <div className="voucher-balance-box"><span>Saldo disponível</span><strong>{formatMT(voucher.balance)}</strong></div>
            </div>
            <Link href="/pos/products" className="primary-button link-button">CONTINUAR PARA PRODUTOS <span>→</span></Link>
          </div>
        )}
      </section>
    </main>
  );
}