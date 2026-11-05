// End-of-cycle report from cycle_summary: a PDF (A4) to hand over, and an
// Excel workbook with one sheet per table for further analysis.

export type CycleSummary = {
  cycle: { code: string; name: string; status: string; amount: number; starts_on: string | null; ends_on: string | null; opened_at: string; closed_at: string | null };
  totals: { households: number; recharged: number; delivered: number; allocated: number; households_bought: number;
    households_with_unused: number; unused: number; women: number; men: number };
  sales: { transactions: number; voucher_amount: number; copayment_amount: number; total_amount: number; discount_amount: number };
  by_district: { province: string | null; district: string | null; households: number; households_bought: number; allocated: number; spent: number; unused: number }[];
  by_gender: { sex: string; households: number; households_bought: number; spent: number }[];
  by_agrodealer: { agrodealer: string; code: string; district: string | null; transactions: number; voucher_amount: number; copayment_amount: number; total_amount: number }[];
  by_product: { product: string; code: string; category: string | null; unit: string; quantity: number; total_amount: number }[];
  substitutes: number;
  with_unused: { household_code: string; representative_name: string; community: string | null; district: string | null; unused: number }[];
};

const mt = (n: number) => `${Number(n || 0).toLocaleString('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} MT`;
const pct = (a: number, b: number) => (b ? `${Math.round((a / b) * 100)}%` : '—');
const date = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : '—');
const SEX: Record<string, string> = { F: 'Mulheres', M: 'Homens', '-': 'Sem registo' };
const fileName = (s: CycleSummary, ext: string) => `relatorio-${s.cycle.code.toLowerCase()}.${ext}`;

export function period(s: CycleSummary) {
  return `${date(s.cycle.starts_on)} – ${s.cycle.status === 'CLOSED' ? date(s.cycle.ends_on) : 'em curso'}`;
}

export async function printCycleReport(s: CycleSummary) {
  const { jsPDF } = await import('jspdf');
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
  const W = 210, H = 297, M = 14;
  let y = M;

  const ensure = (h: number) => { if (y + h > H - M - 8) { doc.addPage(); y = M; } };
  const title = (t: string) => { ensure(14); doc.setFont('helvetica', 'bold'); doc.setFontSize(11.5); doc.text(t, M, y + 5); y += 9; };
  const table = (head: string[], widths: number[], rows: (string | number)[][], alignRight: number[] = []) => {
    const rowH = 6.2;
    const drawHead = () => {
      doc.setFillColor(238, 242, 240); doc.rect(M, y, W - 2 * M, rowH, 'F');
      doc.setFont('helvetica', 'bold'); doc.setFontSize(8);
      let x = M;
      head.forEach((h, i) => { const r = alignRight.includes(i); doc.text(h, r ? x + widths[i] - 1.5 : x + 1.5, y + 4.3, { align: r ? 'right' : 'left' }); x += widths[i]; });
      y += rowH; doc.setFont('helvetica', 'normal');
    };
    ensure(rowH * 2); drawHead();
    rows.forEach((row) => {
      if (y + rowH > H - M - 8) { doc.addPage(); y = M; drawHead(); }
      doc.setFontSize(8.5);
      let x = M;
      row.forEach((c, i) => {
        const r = alignRight.includes(i);
        const text = doc.splitTextToSize(String(c), widths[i] - 3)[0] ?? '';
        doc.text(text, r ? x + widths[i] - 1.5 : x + 1.5, y + 4.3, { align: r ? 'right' : 'left' });
        x += widths[i];
      });
      doc.setDrawColor(220, 224, 222); doc.line(M, y + rowH, W - M, y + rowH);
      y += rowH;
    });
    y += 5;
  };

  const t = s.totals, v = s.sales;
  doc.setFont('helvetica', 'bold'); doc.setFontSize(16);
  doc.text(`Relatório do ${s.cycle.name} (${s.cycle.code})`, M, y + 6);
  doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5);
  doc.text(`Período: ${period(s)} · ${mt(s.cycle.amount)} por agregado · ${s.cycle.status === 'CLOSED' ? 'ciclo fechado' : 'ciclo em curso (valores provisórios)'}`, M, y + 12);
  y += 20;

  title('Resumo');
  table(['Indicador', 'Valor'], [120, W - 2 * M - 120], [
    ['Agregados incluídos no ciclo', t.households],
    ['  cartão entregue neste ciclo / recarregados', `${t.delivered} / ${t.recharged}`],
    ['  mulheres / homens (representante)', `${t.women} / ${t.men}`],
    ['Valor atribuído', mt(t.allocated)],
    ['Agregados que compraram', `${t.households_bought} (${pct(t.households_bought, t.households)})`],
    ['Valor gasto pelo voucher', `${mt(v.voucher_amount)} (${pct(v.voucher_amount, t.allocated)})`],
    ['Copagamento dos agregados', mt(v.copayment_amount)],
    ['Descontos dos agrodealers', mt(v.discount_amount)],
    ['Valor total das compras', mt(v.total_amount)],
    ['Compras (transações)', v.transactions],
    [s.cycle.status === 'CLOSED' ? 'Valor não utilizado (expirou)' : 'Saldo ainda por usar', `${mt(t.unused)} (${t.households_with_unused} agregados)`],
    ['Cartões entregues a substituto', s.substitutes],
  ], [1]);

  title('Por distrito');
  table(['Província', 'Distrito', 'Agregados', 'Compraram', 'Atribuído', 'Gasto', 'Não usado'], [26, 30, 20, 22, 30, 28, 26],
    s.by_district.map((d) => [d.province ?? '—', d.district ?? '—', d.households, d.households_bought, mt(d.allocated), mt(d.spent), mt(d.unused)]), [2, 3, 4, 5, 6]);

  title('Por género do representante');
  table(['Género', 'Agregados', 'Compraram', 'Gasto pelo voucher'], [60, 35, 35, W - 2 * M - 130],
    s.by_gender.map((g) => [SEX[g.sex] ?? g.sex, g.households, g.households_bought, mt(g.spent)]), [1, 2, 3]);

  title('Por agrodealer');
  table(['Agrodealer', 'Distrito', 'Compras', 'Voucher', 'Copagamento', 'Total'], [52, 26, 18, 28, 29, 29],
    s.by_agrodealer.map((a) => [`${a.agrodealer} (${a.code})`, a.district ?? '—', a.transactions, mt(a.voucher_amount), mt(a.copayment_amount), mt(a.total_amount)]), [2, 3, 4, 5]);

  title('Por produto');
  table(['Produto', 'Categoria', 'Quantidade', 'Valor'], [70, 40, 30, W - 2 * M - 140],
    s.by_product.map((p) => [`${p.product} (${p.code})`, p.category ?? '—', `${Number(p.quantity).toLocaleString('pt-PT')} ${p.unit}`, mt(p.total_amount)]), [2, 3]);

  if (s.with_unused.length > 0) {
    title(`Anexo: agregados com valor ${s.cycle.status === 'CLOSED' ? 'não utilizado' : 'por usar'} (${s.with_unused.length})`);
    table(['CODIGO AF', 'Representante', 'Bairro', 'Distrito', 'Valor'], [28, 60, 36, 30, W - 2 * M - 154],
      s.with_unused.map((h) => [h.household_code, h.representative_name, h.community ?? '—', h.district ?? '—', mt(h.unused)]), [4]);
  }

  const pages = doc.getNumberOfPages();
  const generated = new Date().toLocaleString('pt-PT');
  for (let p = 1; p <= pages; p += 1) {
    doc.setPage(p); doc.setFontSize(8); doc.setTextColor(110);
    doc.text(`THANDIZO · ${s.cycle.code} · gerado em ${generated}`, M, H - 7);
    doc.text(`Página ${p} de ${pages}`, W - M, H - 7, { align: 'right' });
    doc.setTextColor(0);
  }
  doc.save(fileName(s, 'pdf'));
}

export async function exportCycleReport(s: CycleSummary) {
  const { default: writeExcelFile } = await import('write-excel-file/browser');
  const bold = (values: string[]) => values.map((value) => ({ value, fontWeight: 'bold' as const }));
  const n = (x: number) => Number(x || 0);
  const t = s.totals, v = s.sales;
  await writeExcelFile([
    { sheet: 'Resumo', columns: [{ width: 44 }, { width: 18 }], data: [
      bold([`${s.cycle.name} (${s.cycle.code})`, period(s)]),
      ['Agregados incluídos', n(t.households)], ['Cartão entregue neste ciclo', n(t.delivered)], ['Recarregados', n(t.recharged)],
      ['Mulheres', n(t.women)], ['Homens', n(t.men)], ['Valor atribuído (MT)', n(t.allocated)], ['Agregados que compraram', n(t.households_bought)],
      ['Valor gasto pelo voucher (MT)', n(v.voucher_amount)], ['Copagamento (MT)', n(v.copayment_amount)], ['Descontos (MT)', n(v.discount_amount)],
      ['Valor total das compras (MT)', n(v.total_amount)], ['Compras', n(v.transactions)],
      [s.cycle.status === 'CLOSED' ? 'Não utilizado - expirou (MT)' : 'Saldo por usar (MT)', n(t.unused)], ['Agregados com valor não usado', n(t.households_with_unused)],
      ['Cartões entregues a substituto', n(s.substitutes)],
    ] },
    { sheet: 'Distritos', columns: [16, 16, 12, 12, 14, 14, 14].map((width) => ({ width })), data: [
      bold(['PROVINCIA', 'DISTRITO', 'AGREGADOS', 'COMPRARAM', 'ATRIBUIDO', 'GASTO', 'NAO USADO']),
      ...s.by_district.map((d) => [d.province ?? '', d.district ?? '', n(d.households), n(d.households_bought), n(d.allocated), n(d.spent), n(d.unused)]),
    ] },
    { sheet: 'Genero', columns: [16, 12, 12, 16].map((width) => ({ width })), data: [
      bold(['GENERO', 'AGREGADOS', 'COMPRARAM', 'GASTO']),
      ...s.by_gender.map((g) => [SEX[g.sex] ?? g.sex, n(g.households), n(g.households_bought), n(g.spent)]),
    ] },
    { sheet: 'Agrodealers', columns: [28, 14, 14, 10, 14, 14, 14].map((width) => ({ width })), data: [
      bold(['AGRODEALER', 'CODIGO', 'DISTRITO', 'COMPRAS', 'VOUCHER', 'COPAGAMENTO', 'TOTAL']),
      ...s.by_agrodealer.map((a) => [a.agrodealer, a.code, a.district ?? '', n(a.transactions), n(a.voucher_amount), n(a.copayment_amount), n(a.total_amount)]),
    ] },
    { sheet: 'Produtos', columns: [28, 12, 16, 10, 12, 14].map((width) => ({ width })), data: [
      bold(['PRODUTO', 'CODIGO', 'CATEGORIA', 'UNIDADE', 'QUANTIDADE', 'VALOR']),
      ...s.by_product.map((p) => [p.product, p.code, p.category ?? '', p.unit, n(p.quantity), n(p.total_amount)]),
    ] },
    { sheet: 'Nao utilizado', columns: [16, 28, 18, 16, 12].map((width) => ({ width })), data: [
      bold(['CODIGO AF', 'REPRESENTANTE', 'BAIRRO', 'DISTRITO', 'VALOR']),
      ...s.with_unused.map((h) => [h.household_code, h.representative_name, h.community ?? '', h.district ?? '', n(h.unused)]),
    ] },
  ]).toFile(fileName(s, 'xlsx'));
}
