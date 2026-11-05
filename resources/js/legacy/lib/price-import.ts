// Price list from the district committees, as an Excel/CSV file filled in by
// MEAL from the template: one row per product, one column per programme
// district with the price there. An empty cell changes nothing.

import { normaliseHeader, parseCsv } from './household-import';

type Cell = string | number | boolean | Date | null | undefined;

export type PriceDistrict = { district_id: string; name: string; province: string };
export type PriceProduct = { product_id: string; code: string; name: string; unit: string; category: string | null; status: string; prices: Record<string, number> };

export type PriceRow = {
  line: number;
  code: string;
  name: string;
  unit: string;
  category: string;
  isNew: boolean;
  // district_id → new price (only the cells that were filled in)
  prices: Record<string, number>;
  // district_id → current price, for the preview
  current: Record<string, number>;
};

export type PriceFile = { rows: PriceRow[]; errors: string[] };

const FIXED = { CODIGO: 'code', PRODUTO: 'name', UNIDADE: 'unit', CATEGORIA: 'category' } as const;

export const TEMPLATE_HELP = [
  'Uma linha por produto e uma coluna por distrito, com o preço em MT por unidade.',
  'Produto que já existe: mantenha o CODIGO do modelo. Produto novo: deixe o CODIGO vazio e preencha PRODUTO e UNIDADE; o código é gerado pelo sistema.',
  'Um CODIGO que não existe é recusado (para um erro de escrita não criar um produto repetido).',
  'Um produto que já existe não muda de nome: só mudam os preços.',
  'Célula de preço vazia: o preço desse distrito fica como está. Para retirar um preço, use o ecrã Produtos.',
  'Os nomes das colunas dos distritos têm de ser iguais aos distritos do programa. Um distrito novo adiciona-se primeiro no ecrã Produtos.',
  'Os preços valem a partir do momento da importação.',
];

// Template pre-filled with the current products and prices.
export async function downloadPriceTemplate(districts: PriceDistrict[], products: PriceProduct[]) {
  const { default: writeExcelFile } = await import('write-excel-file/browser');
  const header = ['CODIGO', 'PRODUTO', 'UNIDADE', 'CATEGORIA', ...districts.map((d) => d.name)]
    .map((value) => ({ value, fontWeight: 'bold' as const }));
  const rows = products.filter((p) => p.status === 'ACTIVE').map((p) => [
    p.code, p.name, p.unit, p.category ?? '',
    ...districts.map((d) => (p.prices[d.district_id] !== undefined ? Number(p.prices[d.district_id]) : null)),
  ]);
  await writeExcelFile([
    { sheet: 'Precos', data: [header, ...rows], columns: [{ width: 16 }, { width: 28 }, { width: 10 }, { width: 14 }, ...districts.map(() => ({ width: 12 }))] },
    { sheet: 'Instrucoes', data: [[{ value: 'Como preencher', fontWeight: 'bold' as const }], ...TEMPLATE_HELP.map((t) => [t])], columns: [{ width: 110 }] },
  ]).toFile('modelo-precos-por-distrito.xlsx');
}

// "1 500,50", "1500.5", 1500.5 → 1500.5; empty → null; anything else → NaN.
export function parsePrice(value: Cell): number | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number') return value;
  let text = String(value).replace(/\s|MT|mt/g, '');
  if (text === '') return null;
  if (text.includes(',') && text.includes('.')) text = text.replace(/\./g, '').replace(',', '.');
  else text = text.replace(',', '.');
  return /^-?\d+(\.\d+)?$/.test(text) ? Number(text) : NaN;
}

export function mapPriceSheet(data: Cell[][], districts: PriceDistrict[], products: PriceProduct[]): PriceFile {
  const errors: string[] = [];
  const headerIndex = data.findIndex((row) => row.some((c) => normaliseHeader(c) === 'CODIGO'));
  if (headerIndex < 0) return { rows: [], errors: ['O ficheiro não tem a coluna CODIGO. Use o modelo.'] };

  const header = data[headerIndex].map(normaliseHeader);
  const fixed: Partial<Record<keyof typeof FIXED, number>> = {};
  const districtCols: { index: number; district: PriceDistrict }[] = [];
  header.forEach((h, index) => {
    if (!h) return;
    if (h in FIXED) { fixed[h as keyof typeof FIXED] = index; return; }
    const district = districts.find((d) => normaliseHeader(d.name) === h);
    if (district) districtCols.push({ index, district });
    else errors.push(`A coluna "${data[headerIndex][index]}" não é um distrito do programa.`);
  });
  if (districtCols.length === 0) errors.push('O ficheiro não tem nenhuma coluna de distrito.');

  const byCode = new Map(products.map((p) => [p.code.toUpperCase(), p]));
  const seen = new Set<string>();
  const rows: PriceRow[] = [];
  const text = (row: Cell[], key: keyof typeof FIXED) => (fixed[key] === undefined ? '' : String(row[fixed[key]!] ?? '').trim());

  data.slice(headerIndex + 1).forEach((row, i) => {
    const line = headerIndex + i + 2;
    if (row.every((c) => c === null || c === undefined || String(c).trim() === '')) return;
    // Empty CODIGO = a new product (the system generates the code).
    const code = text(row, 'CODIGO').toUpperCase();
    const name = text(row, 'PRODUTO');
    const unit = text(row, 'UNIDADE');
    const key = code || `NOVO:${name.toUpperCase()}`;
    if (seen.has(key)) { errors.push(`Linha ${line}: ${code ? `o código ${code}` : `o produto novo ${name}`} aparece mais de uma vez.`); return; }
    seen.add(key);

    const existing = code ? byCode.get(code) : undefined;
    if (code && !existing) { errors.push(`Linha ${line}: o código ${code} não existe. Para um produto novo deixe o CODIGO vazio.`); return; }
    if (existing && existing.status !== 'ACTIVE') { errors.push(`Linha ${line}: o produto ${code} está inativo.`); return; }
    if (!existing && (!name || !unit)) { errors.push(`Linha ${line}: produto novo sem CODIGO; indique PRODUTO e UNIDADE.`); return; }
    if (!existing && products.some((p) => p.name.trim().toUpperCase() === name.toUpperCase())) {
      errors.push(`Linha ${line}: já existe um produto chamado ${name}. Use o CODIGO dele.`); return;
    }

    const prices: Record<string, number> = {};
    let bad = false;
    for (const { index, district } of districtCols) {
      const price = parsePrice(row[index]);
      if (price === null) continue;
      if (!Number.isFinite(price) || price <= 0) {
        errors.push(`Linha ${line}: preço inválido em ${district.name} ("${row[index]}").`);
        bad = true;
        continue;
      }
      prices[district.district_id] = Math.round(price * 100) / 100;
    }
    if (bad) return;

    rows.push({
      line, code, name: existing?.name ?? name, unit: existing?.unit ?? unit, category: existing?.category ?? text(row, 'CATEGORIA'),
      isNew: !existing, prices, current: existing?.prices ?? {},
    });
  });

  if (rows.length === 0 && errors.length === 0) errors.push('O ficheiro não tem linhas de produtos.');
  return { rows, errors };
}

export async function readPriceFile(file: File, districts: PriceDistrict[], products: PriceProduct[]): Promise<PriceFile> {
  const name = file.name.toLowerCase();
  if (name.endsWith('.csv')) return mapPriceSheet(parseCsv(await file.text()), districts, products);
  if (name.endsWith('.xlsx')) {
    const { readSheet } = await import('read-excel-file/browser');
    return mapPriceSheet((await readSheet(file)) as unknown as Cell[][], districts, products);
  }
  throw new Error('IMPORT_FILE_UNREADABLE');
}

// What the import will change, for the preview.
export function priceChanges(rows: PriceRow[]) {
  let changed = 0; let unchanged = 0;
  for (const r of rows) for (const [d, p] of Object.entries(r.prices)) {
    if (r.current[d] !== undefined && Number(r.current[d]) === p) unchanged += 1; else changed += 1;
  }
  return { newProducts: rows.filter((r) => r.isNew).length, changed, unchanged };
}
