// Relatório de transações de um agrodealer num período (PDF, A4), com os
// dados de ReportService::agrodealerReport. Cada pedido fica em report_prints,
// para o gestor MEAL saber quem imprimiu o quê e quando.

export type AgrodealerReport = {
  print_id: string;
  agrodealer: { id: string; code: string; name: string; district: string | null; province: string | null };
  period_start: string; period_end: string; printed_at: string; printed_by: string | null;
  totals: { transactions: number; households: number; subtotal: number; discount_amount: number; total_amount: number;
    voucher_amount: number; copayment_amount: number; reversed: number; settled: number };
  by_product: { code: string; product: string; unit: string; quantity: number; total_amount: number }[];
  rows: { transaction_code: string; sold_at: string; status: string; voucher_code: string; household_code: string;
    representative_name: string; total_amount: number; voucher_amount: number; copayment_amount: number;
    settlement_code: string | null; settlement_status: string | null }[];
};

const mt = (n: number) => `${Number(n || 0).toLocaleString('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} MT`;
const day = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;
const maputo = (iso: string, withTime = true) => new Date(iso).toLocaleString('pt-PT', {
  timeZone: 'Africa/Maputo', day: '2-digit', month: '2-digit', year: 'numeric', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
});
// Where a sale stands in the settlement flow, short enough for its column.
const SETTLED: Record<string, string> = {
  RECONCILED: 'Por enviar', PENDING_MANAGER: 'No gestor', SUBMITTED_TO_FINANCE: 'No Finance', VALIDATED: 'Validada',
};
const oneLine = (v: string) => v.replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();

export const reportFileName = (r: Pick<AgrodealerReport, 'agrodealer' | 'period_start' | 'period_end'>) =>
  `transacoes-${r.agrodealer.code.toLowerCase()}-${r.period_start}-a-${r.period_end}.pdf`;

export async function printAgrodealerReport(r: AgrodealerReport) {
  const { jsPDF } = await import('jspdf');
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
  const W = 210, H = 297, M = 12, avail = W - M * 2;
  let y = M;

  // Cut a text to a column width, with "…" when it does not fit.
  const fit = (value: string, width: number) => {
    let text = oneLine(value);
    if (doc.getTextWidth(text) <= width - 2) return text;
    while (text.length > 1 && doc.getTextWidth(text + '…') > width - 2) text = text.slice(0, -1);
    return text + '…';
  };

  const newPage = () => { doc.addPage(); y = M; };
  const ensure = (h: number, onBreak?: () => void) => { if (y + h > H - 16) { newPage(); onBreak?.(); } };

  // HEADER
  doc.setFillColor(18, 107, 76);
  doc.roundedRect(M, y, avail, 20, 2, 2, 'F');
  doc.setTextColor(255, 255, 255);
  doc.setFont('helvetica', 'bold'); doc.setFontSize(13);
  doc.text('Thandizo · Transações do agrodealer', M + 5, y + 8);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
  doc.text(fit(`${r.agrodealer.name} (${r.agrodealer.code})`, avail - 10), M + 5, y + 14.5);
  y += 26;

  doc.setTextColor(30, 43, 39); doc.setFontSize(8.5);
  const info: [string, string][] = [
    ['Período', `${day(r.period_start)} a ${day(r.period_end)}`],
    ['Local', [r.agrodealer.district, r.agrodealer.province].filter(Boolean).join(', ') || '—'],
    ['Impresso por', `${r.printed_by ?? '—'} em ${maputo(r.printed_at)}`],
    ['Registo', r.print_id.slice(0, 8).toUpperCase()],
  ];
  info.forEach(([k, v], i) => {
    const x = M + (i % 2) * (avail / 2), yy = y + Math.floor(i / 2) * 5.5;
    doc.setFont('helvetica', 'bold'); doc.text(k + ':', x, yy);
    doc.setFont('helvetica', 'normal'); doc.text(fit(v, avail / 2 - 26), x + 24, yy);
  });
  y += 14;

  // SUMMARY (confirmed sales only)
  const t = r.totals;
  doc.setFillColor(234, 246, 240);
  doc.roundedRect(M, y, avail, 26, 2, 2, 'F');
  const cells: [string, string][] = [
    ['Vendas confirmadas', String(t.transactions)], ['Agregados', String(t.households)],
    ['Total das compras', mt(t.total_amount)], ['Pago pelo voucher', mt(t.voucher_amount)],
    ['Copagamento', mt(t.copayment_amount)], ['Descontos', mt(t.discount_amount)],
    ['Já em reconciliação', `${t.settled} de ${t.transactions}`], ['Anuladas (fora dos totais)', String(t.reversed)],
  ];
  cells.forEach(([k, v], i) => {
    const x = M + 4 + (i % 4) * (avail / 4), yy = y + 7 + Math.floor(i / 4) * 11;
    doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(90, 105, 98); doc.text(k, x, yy);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.setTextColor(18, 70, 50); doc.text(v, x, yy + 5);
  });
  y += 32;

  // TABLE helper
  type Col = { label: string; w: number; align?: 'right' };
  const table = (title: string, cols: Col[], rows: string[][], shade?: (i: number) => boolean) => {
    const head = () => {
      doc.setFillColor(231, 242, 236); doc.rect(M, y, avail, 6.5, 'F');
      doc.setFont('helvetica', 'bold'); doc.setFontSize(7); doc.setTextColor(30, 65, 49);
      let x = M;
      for (const c of cols) { doc.text(c.label, c.align === 'right' ? x + c.w - 1.5 : x + 1.5, y + 4.4, c.align === 'right' ? { align: 'right' } : undefined); x += c.w; }
      y += 6.5;
      doc.setFont('helvetica', 'normal'); doc.setTextColor(35, 45, 55);
    };
    ensure(16);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(18, 107, 76);
    doc.text(title, M, y + 3); y += 6;
    head();
    rows.forEach((row, i) => {
      ensure(6, () => { doc.setFont('helvetica', 'italic'); doc.setFontSize(8); doc.setTextColor(18, 107, 76); doc.text(title + ' · continuação', M, y + 3); y += 6; head(); });
      if (shade?.(i)) { doc.setFillColor(253, 243, 242); doc.rect(M, y, avail, 6, 'F'); }
      doc.setFontSize(7);
      let x = M;
      row.forEach((v, j) => {
        const c = cols[j];
        const txt = fit(v, c.w);
        doc.text(txt, c.align === 'right' ? x + c.w - 1.5 : x + 1.5, y + 4.1, c.align === 'right' ? { align: 'right' } : undefined);
        x += c.w;
      });
      doc.setDrawColor(222, 229, 225); doc.line(M, y + 6, W - M, y + 6);
      y += 6;
    });
    if (rows.length === 0) { doc.setFontSize(8); doc.setTextColor(110, 120, 115); doc.text('Sem vendas no período.', M + 1.5, y + 4.5); y += 7; }
    y += 5;
  };

  table('POR PRODUTO', [
    { label: 'CÓDIGO', w: 24 }, { label: 'PRODUTO', w: 86 }, { label: 'UNIDADE', w: 20 },
    { label: 'QUANTIDADE', w: 26, align: 'right' }, { label: 'VALOR', w: avail - 156, align: 'right' },
  ], r.by_product.map((p) => [p.code, p.product, p.unit, Number(p.quantity).toLocaleString('pt-PT'), mt(p.total_amount)]));

  table('VENDAS', [
    { label: 'DATA', w: 23 }, { label: 'REFERÊNCIA', w: 25 }, { label: 'CÓDIGO AF', w: 22 }, { label: 'BENEFICIÁRIO', w: 37 },
    { label: 'TOTAL', w: 20, align: 'right' }, { label: 'VOUCHER', w: 20, align: 'right' }, { label: 'COPAG.', w: 16, align: 'right' },
    { label: 'SITUAÇÃO', w: avail - 163 },
  ], r.rows.map((s) => [
    maputo(s.sold_at), s.transaction_code, s.household_code,
    s.representative_name + (s.status === 'REVERSED' ? ' (anulada)' : ''),
    mt(s.total_amount), mt(s.voucher_amount), mt(s.copayment_amount),
    s.status === 'REVERSED' ? '—' : s.settlement_status ? (SETTLED[s.settlement_status] ?? s.settlement_status) : 'Por reconciliar',
  ]), (i) => r.rows[i].status === 'REVERSED');

  // SIGNATURES
  ensure(28);
  y += 8;
  doc.setDrawColor(150, 160, 155);
  doc.line(M, y + 10, M + 80, y + 10); doc.line(W - M - 80, y + 10, W - M, y + 10);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(70, 80, 75);
  doc.text('Oficial MEAL (nome e assinatura)', M, y + 15);
  doc.text('Agrodealer (nome e assinatura)', W - M - 80, y + 15);

  const pages = doc.getNumberOfPages();
  for (let p = 1; p <= pages; p += 1) {
    doc.setPage(p);
    doc.setDrawColor(220, 228, 223); doc.line(M, H - 10, W - M, H - 10);
    doc.setFontSize(7); doc.setTextColor(100, 112, 105);
    doc.text(`Thandizo · ${r.agrodealer.code} · ${day(r.period_start)} a ${day(r.period_end)} · só vendas confirmadas nos totais`, M, H - 6);
    doc.text(`Página ${p} de ${pages}`, W - M, H - 6, { align: 'right' });
  }
  doc.save(reportFileName(r));
}
