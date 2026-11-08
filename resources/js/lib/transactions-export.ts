// Excel das transações de um período (ReportService::searchTransactions com
// os itens): uma folha com as vendas, outra com os produtos de cada venda e
// o resumo calculado pelo servidor (só vendas confirmadas).

export type TxItem = { product_code: string; product: string; unit: string; quantity: number; unit_price: number; line_total: number };
export type TxRow = {
  id: string; transaction_code: string; status: string; sold_at: string; confirmed_at: string | null;
  agrodealer_id: string; agrodealer: string; agrodealer_code: string; pos_code: string | null; voucher_code: string;
  household_id: string; household_code: string; representative_name: string; district: string | null; province: string | null;
  subtotal: number; discount_amount: number; total_amount: number; voucher_amount: number; copayment_amount: number;
  items?: TxItem[] | null;
};
export type TxSummary = {
  by_status: Record<'CONFIRMED' | 'PENDING' | 'REVERSED' | 'REJECTED', number>;
  subtotal: number; discount_amount: number; total_amount: number; voucher_amount: number; copayment_amount: number;
  households: number; agrodealers: number;
};
export type TxResult = { total: number; summary: TxSummary; agrodealers: { id: string; code: string; name: string }[]; rows: TxRow[] };
export type TxFilters = { from: string; to: string; status: string; agrodealer_id: string; search: string };

export const STATUS_LABEL: Record<string, string> = {
  CONFIRMED: 'Confirmada', PENDING: 'Pendente', REJECTED: 'Rejeitada', REVERSED: 'Anulada',
};

const day = (d: string) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : '');
export const periodLabel = (f: TxFilters) =>
  f.from && f.to ? `${day(f.from)} a ${day(f.to)}` : f.from ? `desde ${day(f.from)}` : f.to ? `até ${day(f.to)}` : 'todo o período';
const maputo = (iso: string) => new Date(iso).toLocaleString('pt-PT', {
  timeZone: 'Africa/Maputo', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
});

export async function exportTransactions(r: TxResult, f: TxFilters, dealerName: string | null) {
  const { default: writeExcelFile } = await import('write-excel-file/browser');
  const bold = (values: string[]) => values.map((value) => ({ value, fontWeight: 'bold' as const }));
  const n = (x: number | null | undefined) => Number(x || 0);
  const s = r.summary;
  const filters = [
    ['Período', periodLabel(f)],
    ['Estado', f.status ? STATUS_LABEL[f.status] ?? f.status : 'Todos'],
    ['Agrodealer', dealerName ?? 'Todos'],
    ...(f.search ? [['Pesquisa', f.search]] : []),
    ['Emitido', new Date().toLocaleString('pt-PT', { timeZone: 'Africa/Maputo' })],
  ];
  await writeExcelFile([
    { sheet: 'Resumo', columns: [{ width: 40 }, { width: 22 }], data: [
      bold(['Thandizo · Transações', '']),
      ...filters,
      [],
      bold(['Valores (só vendas confirmadas)', 'MT']),
      ['Subtotal', n(s.subtotal)], ['Descontos', n(s.discount_amount)], ['Total das compras', n(s.total_amount)],
      ['Pago pelo voucher', n(s.voucher_amount)], ['Copagamento', n(s.copayment_amount)],
      ['Agregados que compraram', n(s.households)], ['Agrodealers com vendas', n(s.agrodealers)],
      [],
      bold(['Vendas por estado', 'Nº']),
      ...(['CONFIRMED', 'PENDING', 'REVERSED', 'REJECTED'] as const).map((k) => [STATUS_LABEL[k], n(s.by_status[k])]),
      ['Total', n(r.total)],
    ] },
    { sheet: 'Transações', columns: [16, 17, 12, 28, 14, 16, 16, 16, 28, 14, 14, 12, 12, 12, 12, 12].map((width) => ({ width })), data: [
      bold(['REFERENCIA', 'DATA (MAPUTO)', 'ESTADO', 'AGRODEALER', 'COD AGRODEALER', 'POS', 'VOUCHER', 'CODIGO AF', 'REPRESENTANTE',
        'DISTRITO', 'PROVINCIA', 'SUBTOTAL', 'DESCONTO', 'TOTAL', 'VOUCHER (MT)', 'COPAGAMENTO']),
      ...r.rows.map((t) => [t.transaction_code, maputo(t.sold_at), STATUS_LABEL[t.status] ?? t.status, t.agrodealer, t.agrodealer_code,
        t.pos_code ?? '', t.voucher_code, t.household_code, t.representative_name, t.district ?? '', t.province ?? '',
        n(t.subtotal), n(t.discount_amount), n(t.total_amount), n(t.voucher_amount), n(t.copayment_amount)]),
    ] },
    { sheet: 'Produtos', columns: [16, 17, 12, 28, 12, 28, 10, 12, 12, 12].map((width) => ({ width })), data: [
      bold(['REFERENCIA', 'DATA (MAPUTO)', 'ESTADO', 'AGRODEALER', 'COD PRODUTO', 'PRODUTO', 'UNIDADE', 'QUANTIDADE', 'PRECO UNIT.', 'VALOR']),
      ...r.rows.flatMap((t) => (t.items ?? []).map((i) => [t.transaction_code, maputo(t.sold_at), STATUS_LABEL[t.status] ?? t.status,
        t.agrodealer, i.product_code, i.product, i.unit, n(i.quantity), n(i.unit_price), n(i.line_total)])),
    ] },
  ]).toFile(`transacoes-${(f.from || 'inicio')}-a-${(f.to || 'hoje')}.xlsx`);
}
