// Printable / exportable lists of households, grouped by a location level.
// Rows come from search_households (with p_sort 'location').

export type ListRow = {
  household_code: string; representative_name: string; representative_sex: string | null;
  representative_birth_date: string | null; representative_relationship: string | null; status: string;
  province: string | null; district: string | null; administrative_post: string | null; locality: string | null; community: string | null;
  voucher_code: string | null; card_status: 'NONE' | 'PENDING' | 'ACTIVE' | 'EMPTY'; current_balance: number | null;
};

export type GroupLevel = 'province' | 'district' | 'administrative_post' | 'locality' | 'community';

export const GROUP_LABEL: Record<GroupLevel, string> = {
  province: 'Província', district: 'Distrito', administrative_post: 'Posto administrativo', locality: 'Localidade', community: 'Bairro / povoação',
};

const LEVELS: GroupLevel[] = ['province', 'district', 'administrative_post', 'locality', 'community'];
const CARD: Record<ListRow['card_status'], string> = { NONE: 'Sem cartão', PENDING: 'Por distribuir', ACTIVE: 'Ativo', EMPTY: 'Sem saldo' };
const GENDER: Record<string, string> = { F: 'F', M: 'M' };

export type ListOptions = { title: string; subtitle: string; groupBy: GroupLevel; signature: boolean; card: boolean };

// "Mutarara · Posto X · Localidade Y · Bairro Z": the place of a group, down to its level.
function groupName(r: ListRow, level: GroupLevel) {
  const upto = LEVELS.slice(0, LEVELS.indexOf(level) + 1);
  return upto.map((l) => r[l] ?? 'Sem ' + GROUP_LABEL[l].toLowerCase()).join(' · ');
}

export function groupRows(rows: ListRow[], level: GroupLevel) {
  const groups = new Map<string, ListRow[]>();
  for (const r of rows) {
    const key = groupName(r, level);
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key)!.push(r);
  }
  return Array.from(groups.entries()).map(([name, items]) => ({ name, items }));
}

const birth = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : '');
// Below the group level, show the place that is still different between rows.
const placeBelow = (r: ListRow, level: GroupLevel) =>
  LEVELS.slice(LEVELS.indexOf(level) + 1).map((l) => r[l]).filter(Boolean).join(' · ');

export async function printHouseholdList(rows: ListRow[], o: ListOptions) {
  const { jsPDF } = await import('jspdf');

  // A household code identifies one family. Guard the printout against repeated
  // records from a search result, and collapse line breaks so every cell stays
  // on a single baseline in the PDF.
  const uniqueRows = Array.from(
    new Map(rows.map((r) => [r.household_code.trim().toUpperCase(), r])).values(),
  );
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape', compress: true });
  const W = 297, H = 210, M = 10, available = W - M * 2;
  const below = o.groupBy !== 'community';
  const cols: { label: string; w: number; get: (r: ListRow, i: number) => string }[] = [
    { label: 'Nº', w: 8, get: (_r, i) => String(i + 1) },
    { label: 'CÓDIGO AF', w: 26, get: (r) => r.household_code },
    { label: 'NOME DO REPRESENTANTE', w: 46, get: (r) => r.representative_name + (r.status !== 'ACTIVE' ? ' (inativo)' : '') },
    { label: 'SEXO', w: 10, get: (r) => GENDER[r.representative_sex ?? ''] ?? '' },
    { label: 'DATA NASC.', w: 19, get: (r) => birth(r.representative_birth_date) },
    { label: 'PARENTESCO', w: 21, get: (r) => r.representative_relationship ?? '' },
  ];
  if (below) cols.push({ label: 'LOCALIZAÇÃO', w: 46, get: (r) => placeBelow(r, o.groupBy) });
  if (o.card) cols.push({ label: 'CARTÃO / VOUCHER', w: 33, get: (r) => r.voucher_code ? r.voucher_code : CARD[r.card_status] });
  const used = cols.reduce((sum, c) => sum + c.w, 0);
  if (o.signature) {
    cols.push({ label: 'ASSINATURA / IMPRESSÃO DIGITAL', w: available - used, get: () => '' });
  } else {
    cols[2].w += available - used;
  }

  const rowH = o.signature ? 10 : 7.2;
  const generated = new Date().toLocaleString('pt-PT', { timeZone: 'Africa/Maputo' });
  let y = M;

  const pageHeader = (first: boolean) => {
    if (!first) doc.addPage();
    y = M;
    doc.setFillColor(18, 107, 76);
    doc.roundedRect(M, y, available, 16, 2, 2, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(first ? 13 : 10);
    doc.text('THANDIZO · LISTA DE BENEFICIÁRIOS', M + 5, y + 6);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.text(o.title, M + 5, y + 11);
    doc.setTextColor(30, 43, 39);
    y += 20;
    doc.setFontSize(8);
    doc.text(o.subtitle, M, y, { maxWidth: available - 62 });
    doc.text('Emitido: ' + generated, W - M, y, { align: 'right' });
    y += 5;
  };

  const tableHead = () => {
    doc.setFillColor(231, 242, 236);
    doc.rect(M, y, available, 7, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(7);
    doc.setTextColor(30, 65, 49);
    let x = M;
    for (const c of cols) {
      doc.text(c.label, x + 1.3, y + 4.6);
      x += c.w;
    }
    y += 7;
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(35, 45, 55);
  };

  const fitText = (value: string, width: number) => {
    let text = value.replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();
    let size = 7.3;
    doc.setFontSize(size);
    while (size > 5.4 && doc.getTextWidth(text) > width - 3) {
      size -= 0.2;
      doc.setFontSize(size);
    }
    while (text.length > 1 && doc.getTextWidth(text) > width - 3) {
      text = text.slice(0, -1);
    }
    if (text !== value && text.length > 3) text = text.slice(0, -1) + '…';
    return text;
  };

  pageHeader(true);
  const groups = groupRows(uniqueRows, o.groupBy);
  let rowIndex = 0;
  for (const group of groups) {
    const groupLabel = GROUP_LABEL[o.groupBy] + ': ' + group.name + '  (' + group.items.length + ' agregado' + (group.items.length === 1 ? '' : 's') + ')';
    if (y + 18 > H - M - 10) pageHeader(false);
    doc.setFillColor(246, 249, 247);
    doc.roundedRect(M, y, available, 7, 1, 1, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8);
    doc.setTextColor(18, 107, 76);
    doc.text(doc.splitTextToSize(groupLabel, available - 4)[0] ?? groupLabel, M + 2, y + 4.7);
    y += 8;
    tableHead();

    for (let i = 0; i < group.items.length; i += 1) {
      const r = group.items[i];
      if (y + rowH > H - M - 10) {
        pageHeader(false);
        doc.setFont('helvetica', 'italic');
        doc.setFontSize(8);
        doc.setTextColor(18, 107, 76);
        doc.text(group.name + ' · continuação', M, y + 4);
        y += 6;
        tableHead();
      }
      if (rowIndex % 2 === 1) {
        doc.setFillColor(250, 252, 251);
        doc.rect(M, y, available, rowH, 'F');
      }
      let x = M;
      for (let j = 0; j < cols.length; j += 1) {
        const col = cols[j];
        const value = col.get(r, i);
        if (value) {
          const fitted = fitText(value, col.w);
          doc.text(fitted, x + 1.3, y + rowH / 2 + 1.1);
        }
        x += col.w;
      }
      doc.setDrawColor(222, 229, 225);
      doc.line(M, y + rowH, W - M, y + rowH);
      y += rowH;
      rowIndex += 1;
    }
    y += 4;
  }

  if (uniqueRows.length === 0) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor(100, 110, 105);
    doc.text('Nenhum agregado corresponde aos filtros seleccionados.', M, y + 8);
  }

  const pages = doc.getNumberOfPages();
  for (let p = 1; p <= pages; p += 1) {
    doc.setPage(p);
    doc.setDrawColor(220, 228, 223);
    doc.line(M, H - 8, W - M, H - 8);
    doc.setFontSize(7);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(100, 112, 105);
    doc.text('THANDIZO · ' + uniqueRows.length.toLocaleString('pt-PT') + ' agregados', M, H - 4);
    doc.text('Página ' + p + ' de ' + pages, W - M, H - 4, { align: 'right' });
  }
  doc.save(o.title.toLowerCase().replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') + '.pdf');
}

export async function exportHouseholdList(rows: ListRow[], o: ListOptions) {
  const { default: writeExcelFile } = await import('write-excel-file/browser');
  const head = ['PROVINCIA', 'DISTRITO', 'P. ADMINISTRATIVO', 'LOCALIDADE', 'BAIRRO/POVOACAO', 'CODIGO AF', 'REPRESENTANTE', 'GENERO',
    'DATA NASC', 'GRAU PARENTESCO', 'SITUACAO', 'CARTAO', 'CODIGO VOUCHER', 'SALDO'].map((value) => ({ value, fontWeight: 'bold' as const }));
  const data = rows.map((r) => [
    r.province ?? '', r.district ?? '', r.administrative_post ?? '', r.locality ?? '', r.community ?? '',
    r.household_code, r.representative_name, r.representative_sex === 'F' ? 'Feminino' : r.representative_sex === 'M' ? 'Masculino' : '',
    birth(r.representative_birth_date), r.representative_relationship ?? '', r.status === 'ACTIVE' ? 'Ativo' : 'Inativo',
    CARD[r.card_status], r.voucher_code ?? '', r.current_balance === null ? null : Number(r.current_balance),
  ]);
  await writeExcelFile([
    { sheet: 'Agregados', data: [head, ...data], columns: [14, 14, 18, 16, 20, 16, 28, 10, 12, 14, 9, 13, 18, 10].map((width) => ({ width })) },
  ]).toFile(`${o.title.toLowerCase().replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}.xlsx`);
}
