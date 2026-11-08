import type { Brand } from '@/types';
import { formatMT } from './format';

// Reconciliação como o servidor a devolve (SettlementService::list).
export type Settlement = {
    id: string; settlement_code: string; agrodealer_id: string; agrodealer: string; province: string | null;
    period_start: string; period_end: string; transaction_count: number;
    gross_amount: number; voucher_amount: number; copayment_amount: number; discount_amount: number; payable_amount: number;
    status: string; notes: string | null; manager_notes: string | null;
    created_at: string; created_by: string | null; submitted_at: string | null; submitted_by: string | null;
    manager_reviewed_at: string | null; manager_reviewed_by: string | null;
};

// Venda incluída numa reconciliação (SettlementService::transactions).
export type SettlementSale = {
    id: string; transaction_code: string; voucher_code: string; total_amount: number; voucher_amount: number;
    copayment_amount: number; status: string; confirmed_at: string;
};

const money = (n: number) => formatMT(Number(n || 0));
const GREEN = [23, 107, 77] as const;

// Mapa de vendas da reconciliação em PDF. O MEAL usa-o como suporte à
// factura do agrodealer; as Finanças como mapa de conferência.
export async function downloadSettlementPdf(s: Settlement, sales: SettlementSale[], kind: 'meal' | 'finance', statusLabel: string, brand: Brand) {
    const { jsPDF } = await import('jspdf');
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const W = 210;
    const meal = kind === 'meal';

    doc.setFont('helvetica', 'bold'); doc.setFontSize(17); doc.setTextColor(...GREEN);
    doc.text(brand.client ? `THANDIZO · ${brand.client}` : 'THANDIZO', 18, 18);
    doc.setFontSize(13); doc.setTextColor(30, 40, 50);
    doc.text(meal ? 'MAPA DE VENDAS E LIQUIDAÇÃO' : 'MAPA DE RECONCILIAÇÃO', 18, 27);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(90, 100, 110);
    doc.text(meal ? 'Documento de suporte à submissão da factura pelo agrodealer' : 'Documento recebido do MEAL para conferência financeira', 18, 34);
    doc.setDrawColor(210, 220, 215); doc.line(18, 39, W - 18, 39);

    doc.setFontSize(9); doc.setTextColor(35, 45, 55);
    const field = (label: string, value: string, y: number) => {
        doc.setFont('helvetica', 'normal'); doc.text(label, 18, y);
        doc.setFont('helvetica', 'bold'); doc.text(value, 48, y);
    };
    field('Agrodealer:', s.agrodealer, 48);
    field('Referência:', s.settlement_code, 55);
    field('Período:', `${s.period_start} a ${s.period_end}`, 62);

    let y = 73;
    doc.setFillColor(234, 246, 240); doc.roundedRect(18, y, W - 36, 39, 3, 3, 'F');
    doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(...GREEN);
    doc.text(meal ? 'RESUMO FINANCEIRO' : 'RESUMO DA RECONCILIAÇÃO', 24, y + 8);
    doc.setFont('helvetica', 'normal'); doc.setTextColor(35, 45, 55); doc.setFontSize(10);
    doc.text(meal ? 'Transações confirmadas' : 'Transações', 24, y + 17); doc.text(String(s.transaction_count), 88, y + 17);
    doc.text(meal ? 'Total das vendas (após desconto)' : 'Vendas', 24, y + 25); doc.text(money(s.gross_amount), 88, y + 25);
    doc.text(meal ? 'Valor coberto por vouchers' : 'Voucher', 112, y + 17); doc.text(money(s.voucher_amount), 112, y + 25);
    doc.text(meal ? 'Copagamentos (já recebidos)' : 'Copagamento', 112, y + 33); doc.text(money(s.copayment_amount), 166, y + 33);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(12);
    doc.text(meal ? 'VALOR A PAGAR' : 'VALOR DA RECONCILIAÇÃO', 24, y + 33); doc.text(money(s.payable_amount), 88, y + 33);

    y += 50;
    doc.setFontSize(10); doc.setTextColor(...GREEN); doc.text('DETALHE DAS TRANSAÇÕES', 18, y); y += 7;
    doc.setFontSize(7); doc.setTextColor(80, 90, 100);
    doc.text('Data', 18, y); doc.text('Voucher', 50, y); doc.text('Total', 108, y); doc.text('Voucher', 139, y); doc.text('Copagamento', 169, y); y += 4;
    doc.setFont('helvetica', 'normal'); doc.setTextColor(35, 45, 55);
    for (const r of sales) {
        if (y > 278) {
            doc.addPage(); y = 18;
            doc.setFont('helvetica', 'bold'); doc.text('DETALHE DAS TRANSAÇÕES (continuação)', 18, y); y += 8;
            doc.setFont('helvetica', 'normal');
        }
        doc.text(new Date(r.confirmed_at).toLocaleDateString('pt-PT'), 18, y);
        doc.text(r.voucher_code.slice(0, 18), 50, y);
        doc.text(money(r.total_amount), 108, y);
        doc.text(money(r.voucher_amount), 139, y);
        doc.text(money(r.copayment_amount), 169, y);
        y += 5;
    }

    if (meal) {
        y += 8; doc.setDrawColor(210, 220, 215); doc.line(18, y, W - 18, y); y += 8;
        doc.setFontSize(8); doc.setTextColor(90, 100, 110);
        doc.text(`Este documento apresenta as transações confirmadas no THANDIZO${brand.programme ? ` (${brand.programme})` : ''} no período indicado.`, 18, y);
        doc.text('A factura deve ser emitida pelo agrodealer de acordo com os procedimentos financeiros aplicáveis.', 18, y + 5);
        doc.setFont('helvetica', 'bold'); doc.setTextColor(35, 45, 55);
        doc.text(`Preparado por: ${s.created_by ?? 'MEAL'}`, 18, y + 18);
        doc.text(`Estado: ${statusLabel}`, 18, y + 25);
        if (s.manager_reviewed_by) doc.text(`Aprovado pelo gestor MEAL: ${s.manager_reviewed_by}`, 18, y + 32);
    }

    doc.save(`${s.settlement_code}.pdf`);
}
