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

    // Moldura de corte discreta
    doc.setDrawColor(190).setLineWidth(0.25).setLineDashPattern([1.1, 1.1], 0);
    doc.roundedRect(x, y, CARD_W, CARD_H, 3, 3, 'S');
    doc.setLineDashPattern([], 0);

    // Cabeçalho institucional moderno
    doc.setFillColor(...BRAND);
    doc.roundedRect(x, y, CARD_W, 13, 3, 3, 'F');
    doc.rect(x, y + 7, CARD_W, 6, 'F');
    doc.setFillColor(255, 255, 255);
    doc.roundedRect(x + 2, y + 1.5, 20, 9.5, 1.4, 1.4, 'F');

    if (logos.length > 0) {
      let lx = x + 3;
      for (const logo of logos.slice(0, 2)) {
        const h = 7.5;
        const w = Math.min(h * logo.ratio, 17);
        if (lx + w > x + 23) break;
        doc.addImage(logo.data, 'PNG', lx, y + 2.5, w, h);
        lx += w + 1.5;
      }
    } else {
      doc.setFont('helvetica', 'bold').setFontSize(5.5).setTextColor(...BRAND);
      doc.text('LINK GCF', x + 12, y + 7.2, { align: 'center' });
    }

    doc.setFont('helvetica', 'bold').setFontSize(8.5).setTextColor(255);
    doc.text('THANDIZO', x + CARD_W - 3, y + 5.2, { align: 'right' });
    doc.setFont('helvetica', 'normal').setFontSize(5.2);
    if (subtitle) doc.text(subtitle.toUpperCase(), x + CARD_W - 3, y + 9.5, { align: 'right', maxWidth: 38 });

    // Dados do agregado: nome, código AF e localização
    const left = x + 4;
    const textWidth = CARD_W - 35;
    doc.setFont('helvetica', 'normal').setTextColor(105).setFontSize(5.2);
    doc.text('REPRESENTANTE DO AGREGADO', left, y + 17);
    doc.setFont('helvetica', 'bold').setTextColor(25).setFontSize(8.2);
    const name = doc.splitTextToSize(card.representative_name.toUpperCase(), textWidth).slice(0, 2);
    doc.text(name, left, y + 21);
    const nameBottom = y + 21 + (name.length - 1) * 3.3;

    doc.setFont('helvetica', 'normal').setTextColor(105).setFontSize(5.2);
    doc.text('CÓDIGO DO AF', left, nameBottom + 4.2);
    doc.setFont('helvetica', 'bold').setTextColor(25).setFontSize(7.4);
    doc.text(card.household_code, left, nameBottom + 7.5);

    const place = [card.community, card.locality].filter(Boolean).join(', ');
    const region = [card.district, card.province].filter(Boolean).join(' · ');
    doc.setFont('helvetica', 'normal').setTextColor(65).setFontSize(5.4);
    doc.text(doc.splitTextToSize(place || '—', textWidth).slice(0, 1), left, y + 39);
    doc.text(doc.splitTextToSize(region || '—', textWidth).slice(0, 1), left, y + 42);

    // QR de validação, isolado numa área limpa
    const qr = await QRCode.toDataURL(card.voucher_code, { errorCorrectionLevel: 'M', margin: 1, width: 320 });
    doc.setDrawColor(155, 220, 170).setLineWidth(0.35);
    doc.roundedRect(x + CARD_W - 27, y + 15, 24, 24, 1.8, 1.8, 'S');
    doc.addImage(qr, 'PNG', x + CARD_W - 25.5, y + 16.5, 21, 21);
    doc.setFont('helvetica', 'normal').setTextColor(85).setFontSize(4.6);
    doc.text('VÁLIDO PARA ESTE AGREGADO', x + CARD_W - 15, y + 41, { align: 'center', maxWidth: 25 });

    // Rodapé verde: código único do voucher
    doc.setFillColor(...BRAND);
    doc.roundedRect(x, y + CARD_H - 9.5, CARD_W, 9.5, 3, 3, 'F');
    doc.rect(x, y + CARD_H - 9.5, CARD_W, 3, 'F');
    doc.setFont('courier', 'bold').setTextColor(255).setFontSize(9.5);
    doc.text(card.voucher_code, x + CARD_W / 2, y + CARD_H - 3.3, { align: 'center' });
  }

  return doc;
}

export async function downloadCardsPdf(cards: VoucherCard[], label: string, subtitle: string, logoUrls: string[]) {
  const doc = await buildCardsPdf(cards, label, subtitle, logoUrls);
  doc.save(`${label}.pdf`);
}
