// Reads the MEAL household list (.xlsx or .csv) in the browser and turns it
// into the rows expected by the import_households RPC. Validation happens in
// the database; this file only finds the columns and converts cell values.

export type ImportRow = {
  row: number;
  province: string;
  district: string;
  administrative_post: string;
  locality: string;
  community: string;
  household_code: string;
  first_name: string;
  last_name: string;
  gender: string;
  birth_date: string;
  relationship: string;
};

type Field = Exclude<keyof ImportRow, 'row'>;
type Cell = string | number | boolean | Date | null | undefined;

// Spreadsheet header (normalised) → RPC key. Several spellings are accepted.
const HEADERS: Record<string, Field> = {
  PROVINCIA: 'province',
  DISTRITO: 'district',
  'P ADMINISTRATIVO': 'administrative_post',
  'POSTO ADMINISTRATIVO': 'administrative_post',
  'P ADM': 'administrative_post',
  LOCALIDADE: 'locality',
  'BAIRRO POVOACAO': 'community',
  BAIRRO: 'community',
  POVOACAO: 'community',
  COMUNIDADE: 'community',
  'CODIGO AF': 'household_code',
  'COD AF': 'household_code',
  NOME: 'first_name',
  APELIDO: 'last_name',
  GENERO: 'gender',
  SEXO: 'gender',
  'DATA NASC': 'birth_date',
  'DATA DE NASCIMENTO': 'birth_date',
  'DATA NASCIMENTO': 'birth_date',
  'GRAU PARENTESCO': 'relationship',
  'GRAU DE PARENTESCO': 'relationship',
  PARENTESCO: 'relationship',
};

export const REQUIRED_HEADERS: { field: Field; label: string }[] = [
  { field: 'province', label: 'PROVINCIA' },
  { field: 'district', label: 'DISTRITO' },
  { field: 'administrative_post', label: 'P. ADMINISTRATIVO' },
  { field: 'locality', label: 'LOCALIDADE' },
  { field: 'community', label: 'BAIRRO/POVOACAO' },
  { field: 'household_code', label: 'CODIGO AF' },
  { field: 'first_name', label: 'NOME' },
  { field: 'last_name', label: 'APELIDO' },
  { field: 'gender', label: 'GENERO' },
  { field: 'birth_date', label: 'DATA NASC' },
  { field: 'relationship', label: 'GRAU PARENTESCO' },
];

export function normaliseHeader(value: Cell): string {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, ' ')
    .trim();
}

const pad = (n: number) => String(n).padStart(2, '0');

function cellText(value: Cell, field: Field): string {
  if (value === null || value === undefined) return '';
  if (value instanceof Date) {
    // Excel dates carry no time zone; read them as UTC calendar dates.
    return `${value.getUTCFullYear()}-${pad(value.getUTCMonth() + 1)}-${pad(value.getUTCDate())}`;
  }
  if (typeof value === 'number' && field === 'birth_date') {
    // A date cell that was not recognised as a date: Excel serial number.
    const date = new Date(Date.UTC(1899, 11, 30) + Math.round(value) * 86400000);
    return cellText(date, field);
  }
  return String(value).trim();
}

export type ParsedList = {
  rows: ImportRow[];
  missingHeaders: string[];
  headerRow: number;
};

// Finds the header row in the first lines and maps every following non-empty row.
export function mapSheet(data: Cell[][]): ParsedList {
  let headerIndex = -1;
  let columns: Partial<Record<Field, number>> = {};

  for (let i = 0; i < Math.min(data.length, 15); i++) {
    const found: Partial<Record<Field, number>> = {};
    data[i].forEach((cell, col) => {
      const field = HEADERS[normaliseHeader(cell)];
      if (field && found[field] === undefined) found[field] = col;
    });
    if (found.household_code !== undefined && found.first_name !== undefined) {
      headerIndex = i;
      columns = found;
      break;
    }
  }

  if (headerIndex < 0) {
    return { rows: [], missingHeaders: REQUIRED_HEADERS.map((h) => h.label), headerRow: 0 };
  }

  const missingHeaders = REQUIRED_HEADERS.filter((h) => columns[h.field] === undefined).map((h) => h.label);
  const rows: ImportRow[] = [];

  for (let i = headerIndex + 1; i < data.length; i++) {
    const line = data[i];
    if (!line || line.every((cell) => cell === null || cell === undefined || String(cell).trim() === '')) continue;
    const row = { row: i + 1 } as ImportRow;
    for (const { field } of REQUIRED_HEADERS) {
      const col = columns[field];
      row[field] = col === undefined ? '' : cellText(line[col], field);
    }
    rows.push(row);
  }

  return { rows, missingHeaders, headerRow: headerIndex + 1 };
}

// Minimal CSV reader: detects ; or , and handles quoted values.
export function parseCsv(text: string): string[][] {
  const clean = text.replace(/^﻿/, '');
  const firstLine = clean.split(/\r?\n/, 1)[0] ?? '';
  const delimiter = (firstLine.match(/;/g)?.length ?? 0) > (firstLine.match(/,/g)?.length ?? 0) ? ';' : ',';
  const rows: string[][] = [];
  let row: string[] = [];
  let value = '';
  let quoted = false;

  for (let i = 0; i < clean.length; i++) {
    const ch = clean[i];
    if (quoted) {
      if (ch === '"' && clean[i + 1] === '"') { value += '"'; i++; }
      else if (ch === '"') quoted = false;
      else value += ch;
    } else if (ch === '"') quoted = true;
    else if (ch === delimiter) { row.push(value); value = ''; }
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && clean[i + 1] === '\n') i++;
      row.push(value); rows.push(row); row = []; value = '';
    } else value += ch;
  }
  if (value !== '' || row.length > 0) { row.push(value); rows.push(row); }
  return rows;
}

export async function readHouseholdFile(file: File): Promise<ParsedList> {
  const name = file.name.toLowerCase();
  if (name.endsWith('.csv')) {
    return mapSheet(parseCsv(await file.text()));
  }
  if (name.endsWith('.xlsx')) {
    const { readSheet } = await import('read-excel-file/browser');
    const data = (await readSheet(file)) as unknown as Cell[][];
    return mapSheet(data);
  }
  throw new Error('IMPORT_FILE_UNREADABLE');
}
