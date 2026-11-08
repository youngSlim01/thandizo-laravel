// Cartões de voucher para imprimir: folhas A4 ao alto com 10 cartões de
// 85 × 54 mm (tamanho de cartão bancário) e linhas de corte. Corre no browser.

export type VoucherCard = {
  voucher_code: string;
  household_code: string;
  representative_name: string;
  community: string | null;
  locality: string | null;
  district: string | null;
  province: string | null;
};

const CARD_W = 85;
const CARD_H = 54;
const COLS = 2;
const ROWS = 5;
const GAP_X = 6;
const GAP_Y = 3;
const MARGIN_X = (210 - COLS * CARD_W - (COLS - 1) * GAP_X) / 2;
const MARGIN_Y = (297 - ROWS * CARD_H - (ROWS - 1) * GAP_Y) / 2;
const BRAND: [number, number, number] = [22, 101, 70];

type Logo = { data: string; ratio: number };

// Desenha a imagem num canvas e devolve-a em PNG, seja qual for o formato
// de origem, para o jsPDF receber sempre PNG. Um logótipo que não carrega é
// ignorado e o cartão sai sem ele.
async function loadLogo(src: string): Promise<Logo | null> {
  try {
    const response = await fetch(src, { credentials: 'same-origin' });
    if (!response.ok || !(response.headers.get('content-type') ?? '').startsWith('image/')) return null;
    const url = URL.createObjectURL(await response.blob());
    try {
      const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = reject;
        el.src = url;
      });
      // Pixéis suficientes para 8 mm de altura na impressão.
      const scale = Math.min(1, 400 / Math.max(img.naturalHeight, 1));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
      canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height);
      return { data: canvas.toDataURL('image/png'), ratio: canvas.width / canvas.height };
    } finally {
      URL.revokeObjectURL(url);
    }
  } catch {
    return null;
  }
}

// subtitle: linha por baixo de THANDIZO no cartão (programa ou organização cliente);
// logoUrls: logótipos do cliente, da esquerda para a direita (Configuração).
export async function buildCardsPdf(cards: VoucherCard[], label: string, subtitle: string, logoUrls: string[]) {
  const [{ jsPDF }, QRCode] = await Promise.all([import('jspdf'), import('qrcode')]);
  const logos = (await Promise.all(logoUrls.map(loadLogo))).filter((l): l is Logo => l !== null);
  const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
  const perPage = COLS * ROWS;
  const pages = Math.max(1, Math.ceil(cards.length / perPage));

  for (let i = 0; i < cards.length; i++) {
    const card = cards[i];
    const slot = i % perPage;
    if (i > 0 && slot === 0) doc.addPage();
    if (slot === 0) {
      doc.setFont('helvetica', 'normal').setFontSize(7).setTextColor(120);
      doc.text(`${label} · folha ${Math.floor(i / perPage) + 1} de ${pages} · ${cards.length} cartões`, 105, 297 - 2.5, { align: 'center' });
    }
    const x = MARGIN_X + (slot % COLS) * (CARD_W + GAP_X);
    const y = MARGIN_Y + Math.floor(slot / COLS) * (CARD_H + GAP_Y);

    // Cut line
    doc.setDrawColor(170).setLineWidth(0.2).setLineDashPattern([1.2, 1.2], 0);
    doc.roundedRect(x, y, CARD_W, CARD_H, 3, 3, 'S');
    doc.setLineDashPattern([], 0);

    // Header band with the logos, or the programme name while there are none
    doc.setFillColor(...BRAND);
    doc.roundedRect(x, y, CARD_W, 12, 3, 3, 'F');
    doc.rect(x, y + 6, CARD_W, 6, 'F');
    if (logos.length > 0) {
      let lx = x + 3;
      for (const logo of logos) {
        const h = 8;
        const w = Math.min(h * logo.ratio, 26);
        doc.setFillColor(255, 255, 255);
        doc.roundedRect(lx - 0.8, y + 1.2, w + 1.6, h + 1.6, 1, 1, 'F');
        doc.addImage(logo.data, 'PNG', lx, y + 2, w, h);
        lx += w + 3;
      }
    }
    doc.setFont('helvetica', 'bold').setFontSize(8).setTextColor(255);
    doc.text('Thandizo', x + CARD_W - 3, y + 5.2, { align: 'right' });
    doc.setFont('Lato', 'normal').setFontSize(6.5);
    if (subtitle) doc.text(subtitle.toUpperCase(), x + CARD_W - 3, y + 9, { align: 'right' });

    // Representative and household
    const left = x + 4;
    const textWidth = CARD_W - 4 - 30;
    doc.setTextColor(110).setFontSize(6);
    doc.text('REPRESENTANTE', left, y + 17);
    doc.setTextColor(20).setFont('helvetica', 'bold').setFontSize(9);
    const name = doc.splitTextToSize(card.representative_name.toUpperCase(), textWidth).slice(0, 2);
    doc.text(name, left, y + 21);
    let cursor = y + 21 + (name.length - 1) * 3.8 + 5;

    doc.setFont('helvetica', 'normal').setTextColor(110).setFontSize(6);
    doc.text('CÓDIGO DO AF', left, cursor);
    doc.setTextColor(20).setFont('helvetica', 'bold').setFontSize(8.5);
    doc.text(card.household_code, left, cursor + 3.6);
    cursor += 8;

    const place = [card.community, card.locality].filter(Boolean).join(', ');
    const region = [card.district, card.province].filter(Boolean).join(' · ');
    doc.setFont('helvetica', 'normal').setTextColor(60).setFontSize(6.5);
    doc.text(doc.splitTextToSize(place || '—', textWidth).slice(0, 1), left, cursor);
    doc.text(doc.splitTextToSize(region || '—', textWidth).slice(0, 1), left, cursor + 3);

    // Voucher code, as typed at the POS
    doc.setFont('courier', 'bold').setTextColor(...BRAND).setFontSize(11);
    doc.text(card.voucher_code, left, y + CARD_H - 4);

    // QR code with the voucher code
    const qr = await QRCode.toDataURL(card.voucher_code, { errorCorrectionLevel: 'M', margin: 0, width: 320 });
    doc.addImage(qr, 'PNG', x + CARD_W - 27, y + 15.5, 23, 23);
    doc.setFont('helvetica', 'normal').setTextColor(110).setFontSize(5.5);
    doc.text('Válido apenas para o agregado indicado', x + CARD_W - 15.5, y + 42, { align: 'center', maxWidth: 26 });
  }

  return doc;
}

export async function downloadCardsPdf(cards: VoucherCard[], label: string, subtitle: string, logoUrls: string[]) {
  const doc = await buildCardsPdf(cards, label, subtitle, logoUrls);
  doc.save(`${label}.pdf`);
}
