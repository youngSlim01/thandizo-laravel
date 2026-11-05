// Leitura de folhas de cálculo partilhada pelas importações (preços agora,
// agregados familiares no nível 6). Vem de lib/household-import.ts do Next.js.

type Cell = string | number | boolean | Date | null | undefined;

// "Código AF" → "CODIGO AF": sem acentos, maiúsculas, só letras e números.
export function normaliseHeader(value: Cell): string {
  return String(value ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, ' ')
    .trim();
}

// CSV com vírgula ou ponto e vírgula (o Excel em português usa ;).
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
