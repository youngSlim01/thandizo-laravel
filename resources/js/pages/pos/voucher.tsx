import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { QrScanner } from '@/components/qr-scanner';
import { CheckoutSteps, PosHeader } from '@/components/pos/pos-header';
import { useTransaction } from '@/components/pos/transaction';
import { formatMT } from '@/lib/format';
import { errorText, requestJson } from '@/lib/http';
import { cardCodeLooksValid } from '@/lib/voucher-code';

type LookupResult = {
    voucher_code: string; voucher_status: string; household_status?: string; household_code: string;
    representative_name: string; current_balance: number;
};

// Situações do voucher em que não se pode vender, verificadas antes de seguir.
function refusal(data: LookupResult): string | null {
    if (data.voucher_status === 'PENDING') return 'Este cartão ainda não foi ativado: o lote não foi marcado como distribuído. Contacte o MEAL.';
    if (data.voucher_status === 'REPLACED') return 'Este cartão foi substituído por um cartão novo e já não pode ser usado.';
    if (data.voucher_status !== 'ACTIVE') return 'Este voucher não está ativo.';
    if (data.household_status && data.household_status !== 'ACTIVE') return 'Este agregado está desativado e não pode comprar. O saldo está guardado; contacte o MEAL.';
    // O copagamento só complementa o voucher: sem saldo não há compra.
    if (Number(data.current_balance) <= 0) return 'Este voucher já não tem saldo. Não é possível fazer compras, nem pagando em dinheiro.';
    return null;
}

export default function VoucherStep() {
    const transaction = useTransaction();
    const [code, setCode] = useState(transaction.voucher?.code ?? '');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [scanning, setScanning] = useState(false);

    // Depois de uma venda confirmada, voltar aqui começa uma venda nova.
    useEffect(() => {
        if (transaction.result) { transaction.resetTransaction(); setCode(''); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    async function lookup(value: string) {
        setError(null);
        if (!cardCodeLooksValid(value)) { setError('O código parece ter um erro de digitação. Verifique os números no cartão.'); return; }
        setLoading(true);
        try {
            const data = await requestJson<LookupResult>('POST', '/pos/lookup', { voucher_code: value.trim() });
            const refused = refusal(data);
            if (refused) setError(refused);
            else transaction.setVoucher({ code: data.voucher_code, householdCode: data.household_code, representativeName: data.representative_name, balance: Number(data.current_balance) });
        } catch (err) { setError(errorText(err)); } finally { setLoading(false); }
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        void lookup(code);
    }

    // O QR code só tem o código do voucher; outra coisa é recusada antes da consulta.
    const scanned = useCallback((text: string) => {
        setScanning(false);
        const value = text.trim().toUpperCase();
        if (!/^[A-Z0-9-]{4,40}$/.test(value)) { setError('Este QR code não é de um cartão de voucher.'); return; }
        setCode(value);
        void lookup(value);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const voucher = transaction.voucher;

    return (
        <main className="pos-shell">
            <Head title="Nova venda" />
            <PosHeader title="Nova venda" back="/" />
            <section className="pos-content">
                <CheckoutSteps current={0} />
                <div className="page-heading-row">
                    <div><h1>Consultar voucher</h1><p>Introduza ou leia o código do voucher do agregado familiar.</p></div>
                    <span className="balance-pill">POS {voucher ? 'activo' : 'pronto'}</span>
                </div>

                <div className="voucher-entry-layout">
                    <form onSubmit={submit} className="voucher-entry-card">
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
